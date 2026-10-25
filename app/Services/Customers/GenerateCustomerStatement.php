<?php

namespace App\Services\Customers;

use App\Models\Customer;
use App\Models\CustomerCreditTransaction;
use App\Models\Sale;
use App\Models\SalePayment;
use App\Models\SaleReturn;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Builds the data backbone of a Customer Statement — the dated event
 * log + running balance you hand to the customer when they ask "why do
 * I owe you this much?".
 *
 * Events captured per (customer, dateFrom..dateTo):
 *   - SALE       → debit by `grand_total` (we billed the customer)
 *   - PAYMENT    → credit by `amount` (any sale_payment row tied to
 *                  the customer, including sale-time tenders)
 *   - CREDIT TRANSFER → debit by a historical negative A/R balance moved
 *                  into the dedicated store-credit liability
 *   - REFUND     → credit by `grand_total` (cash/store credit returned)
 *   - VOID       → credit by the original sale's `grand_total` (the
 *                  bill is reversed; cash refund is physical)
 *
 * Opening balance = same math applied to everything BEFORE dateFrom,
 * starting from 0. Closing balance = opening + period_debits − period_credits
 * (and matches `customers.outstanding_balance` when dateTo = today).
 *
 * The shape is deliberately pure-array — easy to render in Blade,
 * easy to attach to a Mailable, easy to seed a future PDF renderer.
 *
 * @return array{
 *   customer:           Customer,
 *   from:               CarbonImmutable,
 *   to:                 CarbonImmutable,
 *   opening_balance:    string,
 *   closing_balance:    string,
 *   debits_total:       string,
 *   credits_total:      string,
 *   events:             Collection<int, array<string, mixed>>,
 * }
 */
class GenerateCustomerStatement
{
    /** @return array<string, mixed> */
    public function __invoke(
        Customer $customer,
        ?CarbonImmutable $from = null,
        ?CarbonImmutable $to = null,
    ): array {
        $to   = ($to   ?? CarbonImmutable::today())->endOfDay();
        $from = ($from ?? $to->subMonths(3))->startOfDay();

        // Opening balance — apply the same SALES − PAYMENTS − REFUNDS
        // accounting to every event dated before `from`. Starting from 0,
        // because credit history began at install time.
        $opening = $this->balanceBefore($customer, $from);

        // Period events, sorted by date. Each row is one journal-like
        // line with EITHER a debit or a credit populated, never both.
        $events = $this->periodEvents($customer, $from, $to);

        $running     = $opening;
        $debitsSum   = '0';
        $creditsSum  = '0';

        $events = $events->map(function (array $event) use (&$running, &$debitsSum, &$creditsSum) {
            $debit  = (string) ($event['debit']  ?? '0');
            $credit = (string) ($event['credit'] ?? '0');
            $debitsSum  = bcadd($debitsSum,  $debit,  4);
            $creditsSum = bcadd($creditsSum, $credit, 4);
            $running    = bcsub(bcadd($running, $debit, 4), $credit, 4);
            $event['running_balance'] = $running;
            return $event;
        });

        return [
            'customer'        => $customer,
            'from'            => $from->startOfDay(),
            'to'              => $to->startOfDay(),
            'opening_balance' => $opening,
            'closing_balance' => $running,
            'debits_total'    => $debitsSum,
            'credits_total'   => $creditsSum,
            'events'          => $events,
        ];
    }

    /** Running balance contributed by everything BEFORE `$from`. */
    private function balanceBefore(Customer $customer, CarbonImmutable $from): string
    {
        // Voided sales are excluded entirely — they never "really"
        // billed the customer, so they don't appear on the statement
        // either as a debit or as a void credit. Cleanest model: as
        // far as the customer is concerned, the sale never happened.
        $sales = DB::table('sales')
            ->where('customer_id', $customer->id)
            ->whereNull('deleted_at')
            ->where('status', '!=', Sale::STATUS_VOIDED)
            ->whereDate('sale_date', '<', $from->toDateString())
            ->sum('grand_total');

        $payments = DB::table('sale_payments as p')
            ->join('sales as s', 's.id', '=', 'p.sale_id')
            ->whereNull('s.deleted_at')
            ->where('s.customer_id', $customer->id)
            ->whereDate('p.paid_at', '<', $from->toDateString())
            ->sum('p.amount');

        // Settlement-only rows (sale_id NULL, customer_id set) — pure
        // customer credit, never tied to a sale.
        $settlementCredits = DB::table('sale_payments')
            ->where('customer_id', $customer->id)
            ->whereNull('sale_id')
            ->whereDate('paid_at', '<', $from->toDateString())
            ->sum('amount');

        // A customer-payment overage now moves into the separate store-credit
        // liability. It is real cash received, but it did not settle A/R and
        // therefore must not make this receivables statement go negative.
        $storeCreditAdds = DB::table('customer_credit_transactions as ct')
            ->join('sale_payments as p', function ($join) {
                $join->on('p.id', '=', 'ct.reference_id')
                    ->where('ct.reference_type', '=', SalePayment::class);
            })
            ->where('ct.customer_id', $customer->id)
            ->where('ct.type', CustomerCreditTransaction::TYPE_CREDIT_ADD)
            ->whereDate('p.paid_at', '<', $from->toDateString())
            ->sum('ct.amount');

        // Only the slice that actually settled the receivable
        // (code-review #67): crediting the full grand_total made a
        // paid-then-refunded cash sale close at −grand while outstanding
        // stayed 0 — the statement never reconciled. The payout portion
        // (cash/gateway back) never touches A/R. Legacy refunds carry 0
        // here AND never reduced outstanding, so old data stays
        // consistent too.
        $refunds = DB::table('sale_returns as r')
            ->join('sales as s', 's.id', '=', 'r.sale_id')
            ->whereNull('s.deleted_at')
            ->where('s.customer_id', $customer->id)
            ->whereDate('r.return_date', '<', $from->toDateString())
            ->sum('r.refunded_against_balance');

        $creditTransfers = DB::table('customer_credit_transactions')
            ->where('customer_id', $customer->id)
            ->where('type', CustomerCreditTransaction::TYPE_CREDIT_CONVERT)
            ->whereDate('created_at', '<', $from->toDateString())
            ->sum('amount');

        // bcsub((bcadd(sales,0)), (payments+settlement+refunds), 4)
        $debit = bcadd((string) ($sales ?? '0'), (string) ($creditTransfers ?? '0'), 4);
        $paymentCredits = bcsub(
            bcadd((string) ($payments ?? '0'), (string) ($settlementCredits ?? '0'), 4),
            (string) ($storeCreditAdds ?? '0'),
            4,
        );
        $credit = bcadd($paymentCredits, (string) ($refunds ?? '0'), 4);

        return bcsub($debit, $credit, 4);
    }

    /** @return Collection<int, array<string, mixed>> */
    private function periodEvents(Customer $customer, CarbonImmutable $from, CarbonImmutable $to): Collection
    {
        $events = collect();

        // ─── Sales ──────────────────────────────────────────────────
        // Voided sales are excluded entirely (consistent with the
        // opening-balance math in balanceBefore()).
        Sale::query()
            ->where('customer_id', $customer->id)
            ->where('status', '!=', Sale::STATUS_VOIDED)
            ->whereDate('sale_date', '>=', $from->toDateString())
            ->whereDate('sale_date', '<=', $to->toDateString())
            ->orderBy('sale_date')->orderBy('id')
            ->get(['id', 'number', 'sale_date', 'grand_total', 'status'])
            ->each(function (Sale $s) use ($events) {
                $events->push([
                    'date'         => $s->sale_date->format('Y-m-d'),
                    'sort_seq'     => 0, // sales render before payments same day
                    'type'         => 'sale',
                    'reference'    => $s->number,
                    'sale_id'      => $s->id,
                    'debit'        => (string) $s->grand_total,
                    'credit'       => '0',
                ]);
            });

        // ─── Payments tied to this customer's sales ────────────────
        $paymentRows = SalePayment::query()
            ->where(function ($q) use ($customer) {
                $q->where('customer_id', $customer->id)
                  ->orWhereHas('sale', fn ($q) => $q->where('customer_id', $customer->id));
            })
            ->whereBetween('paid_at', [$from->startOfDay(), $to->endOfDay()])
            ->with('sale:id,number')
            ->orderBy('paid_at')->orderBy('id')
            ->get();

        $storeCreditByPayment = CustomerCreditTransaction::query()
            ->where('customer_id', $customer->id)
            ->where('type', CustomerCreditTransaction::TYPE_CREDIT_ADD)
            ->where('reference_type', SalePayment::class)
            ->whereIn('reference_id', $paymentRows->pluck('id'))
            ->get(['reference_id', 'amount'])
            ->groupBy('reference_id')
            ->map(fn ($rows) => $rows->reduce(
                fn (string $sum, CustomerCreditTransaction $row) => bcadd($sum, (string) $row->amount, 4),
                '0.0000',
            ));

        $paymentRows->each(function (SalePayment $p) use ($events, $storeCreditByPayment) {
            $receivableCredit = bcsub(
                (string) $p->amount,
                (string) ($storeCreditByPayment->get($p->id) ?? '0'),
                4,
            );
            if (bccomp($receivableCredit, '0', 4) <= 0) {
                return;
            }
            $events->push([
                    'date'         => CarbonImmutable::parse($p->paid_at)->format('Y-m-d'),
                    'sort_seq'     => 1,
                    'type'         => 'payment',
                    'reference'    => $p->sale?->number ?? '—',
                    'sale_id'      => $p->sale_id,
                    'debit'        => '0',
                    'credit'       => $receivableCredit,
            ]);
        });

        // A legacy overpayment used to be represented as negative A/R.
        // When checkout converts it to store credit, debit A/R by the
        // converted amount so this statement returns from negative to zero.
        CustomerCreditTransaction::query()
            ->where('customer_id', $customer->id)
            ->where('type', CustomerCreditTransaction::TYPE_CREDIT_CONVERT)
            ->whereBetween('created_at', [$from->startOfDay(), $to->endOfDay()])
            ->with('reference')
            ->orderBy('created_at')->orderBy('id')
            ->get()
            ->each(function (CustomerCreditTransaction $transfer) use ($events) {
                $sale = $transfer->reference instanceof Sale ? $transfer->reference : null;
                $events->push([
                    'date'      => $transfer->created_at->format('Y-m-d'),
                    'sort_seq'  => 2,
                    'type'      => 'credit_transfer',
                    'reference' => $sale?->number ?? '—',
                    'sale_id'   => $sale?->id,
                    'debit'     => (string) $transfer->amount,
                    'credit'    => '0',
                ]);
            });

        // ─── Refunds ───────────────────────────────────────────────
        // A refund only appears on the RECEIVABLES statement to the
        // extent it settled the sale's unpaid balance (code-review #67);
        // a cash refund of a fully-paid sale is till money, not an A/R
        // event, and crediting it broke closing == outstanding.
        SaleReturn::query()
            ->whereHas('sale', fn ($q) => $q->where('customer_id', $customer->id))
            ->whereDate('return_date', '>=', $from->toDateString())
            ->whereDate('return_date', '<=', $to->toDateString())
            ->whereRaw('refunded_against_balance > 0')
            ->with('sale:id,number')
            ->orderBy('return_date')->orderBy('id')
            ->get(['id', 'sale_id', 'number', 'return_date', 'refunded_against_balance'])
            ->each(function (SaleReturn $r) use ($events) {
                $events->push([
                    'date'         => $r->return_date->format('Y-m-d'),
                    'sort_seq'     => 3,
                    'type'         => 'refund',
                    'reference'    => $r->number,
                    'sale_id'      => $r->sale_id,
                    'debit'        => '0',
                    'credit'       => (string) $r->refunded_against_balance,
                ]);
            });

        return $events
            ->sortBy([['date', 'asc'], ['sort_seq', 'asc']])
            ->values();
    }
}
