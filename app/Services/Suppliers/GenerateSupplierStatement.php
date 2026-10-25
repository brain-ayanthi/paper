<?php

namespace App\Services\Suppliers;

use App\Models\Purchase;
use App\Models\PurchasePayment;
use App\Models\PurchaseReturn;
use App\Models\Supplier;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Builds the dated event log + running balance for what WE owe a supplier
 * — the payables mirror of {@see \App\Services\Customers\GenerateCustomerStatement}.
 *
 * The sign convention is inverted relative to the customer statement,
 * because the money flows the other way. Here the running balance is
 * "what we still owe this supplier", matching `suppliers.outstanding_balance`:
 *
 *   - PURCHASE → credit by `grand_total` (they billed us; we owe more)
 *   - PAYMENT  → debit  by `amount`      (we paid; we owe less)
 *   - RETURN   → debit  by `grand_total` (we sent goods back; we owe less)
 *
 * Two payables-specific rules the sales side has no equivalent for:
 *
 *   1. **Voided payments are excluded.** `purchase_payments` soft-marks a
 *      void via `voided_at` and {@see \App\Actions\Purchases\VoidSupplierPayment}
 *      already reverses the cash effect, so counting a voided row here
 *      would double-reverse it.
 *   2. **One user payment can be many rows.** A payment split across N POs
 *      writes N rows sharing one `client_uuid`. They're collapsed into a
 *      single statement line, or the supplier sees one $500 payment as
 *      three. Rows with no `client_uuid` stand alone.
 *
 * Cancelled purchases are excluded entirely — same treatment as voided
 * sales on the customer statement: as far as the supplier relationship
 * goes, the order never happened.
 *
 * Opening balance = the same maths applied to everything before `from`,
 * starting from 0. Closing = opening + credits − debits, and matches
 * `suppliers.outstanding_balance` when `to` is today.
 *
 * @return array{
 *   supplier:           Supplier,
 *   from:               CarbonImmutable,
 *   to:                 CarbonImmutable,
 *   opening_balance:    string,
 *   closing_balance:    string,
 *   debits_total:       string,
 *   credits_total:      string,
 *   events:             Collection<int, array<string, mixed>>,
 * }
 */
class GenerateSupplierStatement
{
    /** @return array<string, mixed> */
    public function __invoke(
        Supplier $supplier,
        ?CarbonImmutable $from = null,
        ?CarbonImmutable $to = null,
    ): array {
        $to   = ($to   ?? CarbonImmutable::today())->endOfDay();
        $from = ($from ?? $to->subMonths(3))->startOfDay();

        $opening = $this->balanceBefore($supplier, $from);

        $events = $this->periodEvents($supplier, $from, $to);

        $running    = $opening;
        $debitsSum  = '0';
        $creditsSum = '0';

        $events = $events->map(function (array $event) use (&$running, &$debitsSum, &$creditsSum) {
            $debit  = (string) ($event['debit']  ?? '0');
            $credit = (string) ($event['credit'] ?? '0');
            $debitsSum  = bcadd($debitsSum,  $debit,  4);
            $creditsSum = bcadd($creditsSum, $credit, 4);
            // Credits raise what we owe, debits reduce it — the mirror of
            // the customer ledger, where a debit is what they owe us.
            $running    = bcsub(bcadd($running, $credit, 4), $debit, 4);
            $event['running_balance'] = $running;

            return $event;
        });

        return [
            'supplier'        => $supplier,
            'from'            => $from->startOfDay(),
            'to'              => $to->startOfDay(),
            'opening_balance' => $opening,
            'closing_balance' => $running,
            'debits_total'    => $debitsSum,
            'credits_total'   => $creditsSum,
            'events'          => $events,
        ];
    }

    /** Balance owed from everything dated BEFORE `$from`. */
    private function balanceBefore(Supplier $supplier, CarbonImmutable $from): string
    {
        $purchases = DB::table('purchases')
            ->where('supplier_id', $supplier->id)
            ->whereNull('deleted_at')
            ->where('status', '!=', Purchase::STATUS_CANCELLED)
            ->whereDate('purchase_date', '<', $from->toDateString())
            ->sum('grand_total');

        $payments = DB::table('purchase_payments')
            ->where('supplier_id', $supplier->id)
            ->whereNull('voided_at')
            // A purchase-linked row with no method is an application of an
            // earlier advance, not another payment. The original advance is
            // already the statement debit, so counting this row would double it.
            ->where(function ($query) {
                $query->whereNull('purchase_id')->orWhereNotNull('payment_method_id');
            })
            ->whereDate('payment_date', '<', $from->toDateString())
            ->sum('amount');

        $returns = DB::table('purchase_returns')
            ->where('supplier_id', $supplier->id)
            ->whereNull('deleted_at')
            ->where('status', PurchaseReturn::STATUS_POSTED)
            ->whereDate('return_date', '<', $from->toDateString())
            ->sum('grand_total');

        $credit = (string) ($purchases ?? '0');
        $debit  = bcadd((string) ($payments ?? '0'), (string) ($returns ?? '0'), 4);

        return bcsub($credit, $debit, 4);
    }

    /** @return Collection<int, array<string, mixed>> */
    private function periodEvents(Supplier $supplier, CarbonImmutable $from, CarbonImmutable $to): Collection
    {
        $events = collect();

        // ─── Purchases ──────────────────────────────────────────────
        Purchase::query()
            ->where('supplier_id', $supplier->id)
            ->where('status', '!=', Purchase::STATUS_CANCELLED)
            ->whereBetween('purchase_date', [$from->toDateString(), $to->toDateString()])
            ->orderBy('purchase_date')->orderBy('id')
            ->get(['id', 'number', 'purchase_date', 'grand_total', 'status'])
            ->each(function (Purchase $p) use ($events) {
                $events->push([
                    'date'        => $p->purchase_date->format('Y-m-d'),
                    'sort_seq'    => 0, // purchases render before payments same day
                    'type'        => 'purchase',
                    'reference'   => $p->number,
                    'purchase_id' => $p->id,
                    'debit'       => '0',
                    'credit'      => (string) $p->grand_total,
                ]);
            });

        // ─── Payments ───────────────────────────────────────────────
        // Collapsed by `client_uuid`: one user action that allocated
        // across several POs is one line on the statement. The reference
        // names the PO when a group only touched one, otherwise it says
        // how many the payment was spread over.
        PurchasePayment::query()
            ->where('supplier_id', $supplier->id)
            ->whereNull('voided_at')
            ->where(function ($query) {
                $query->whereNull('purchase_id')->orWhereNotNull('payment_method_id');
            })
            ->whereBetween('payment_date', [$from->toDateString(), $to->toDateString()])
            ->with('purchase:id,number')
            ->orderBy('payment_date')->orderBy('id')
            ->get()
            ->groupBy(fn (PurchasePayment $p) => $p->client_uuid ?: 'row-'.$p->id)
            ->each(function (Collection $group) use ($events) {
                /** @var PurchasePayment $first */
                $first    = $group->first();
                $amount   = $group->reduce(fn ($carry, $p) => bcadd($carry, (string) $p->amount, 4), '0');
                $numbers  = $group->pluck('purchase.number')->filter()->unique()->values();

                $events->push([
                    'date'        => CarbonImmutable::parse($first->payment_date)->format('Y-m-d'),
                    'sort_seq'    => 1,
                    'type'        => 'payment',
                    'reference'   => match (true) {
                        $numbers->count() === 1 => $numbers->first(),
                        $numbers->count() > 1   => __('supplier_statement.types.split', ['count' => $numbers->count()]),
                        default                 => __('supplier_statement.types.unallocated'),
                    },
                    'purchase_id' => $numbers->count() === 1 ? $first->purchase_id : null,
                    'debit'       => $amount,
                    'credit'      => '0',
                ]);
            });

        // ─── Returns ────────────────────────────────────────────────
        PurchaseReturn::query()
            ->where('supplier_id', $supplier->id)
            ->where('status', PurchaseReturn::STATUS_POSTED)
            ->whereBetween('return_date', [$from->toDateString(), $to->toDateString()])
            ->with('purchase:id,number')
            ->orderBy('return_date')->orderBy('id')
            ->get(['id', 'purchase_id', 'number', 'return_date', 'grand_total'])
            ->each(function (PurchaseReturn $r) use ($events) {
                $events->push([
                    'date'        => $r->return_date->format('Y-m-d'),
                    'sort_seq'    => 2,
                    'type'        => 'return',
                    'reference'   => $r->number,
                    'purchase_id' => $r->purchase_id,
                    'debit'       => (string) $r->grand_total,
                    'credit'      => '0',
                ]);
            });

        return $events
            ->sortBy([['date', 'asc'], ['sort_seq', 'asc']])
            ->values();
    }
}
