<?php

namespace App\Actions\Accounting;

use App\Actions\Accounting\Concerns\GuardsAgainstDoublePosting;
use App\Models\JournalEntry;
use App\Models\PaymentMethod;
use App\Models\Sale;
use App\Services\Accounting\AccountMappingResolver;
use App\Services\Accounting\PaymentAccountResolver;

/**
 * Turns a completed sale into its journal entry (docs/features/accounting.md
 * §5.3). Built from the sale's own money columns so it balances by
 * construction:
 *
 *   Dr  payment account(s)   net cash received per tender
 *   Dr  A/R — Customers      balance_due          (credit sales)
 *   Dr  Sales Discounts      discount_total       (contra-revenue, if any)
 *     Cr  Sales — Revenue      subtotal + discount + charges
 *     Cr  Tax Output           tax_total            (if any)
 *   Dr  COGS  /  Cr Inventory  Σ qty × WAC          (cost relief, if any)
 *
 * COGS uses each sale item's cost snapshot, preserving batch cost and the
 * actual sale-time WAC even when inventory costs change later.
 *
 * Idempotent (skips a sale that already has an entry) and side-effect-light:
 * the `sale.after_complete` listener wraps this so a posting failure is logged
 * but never blocks checkout.
 */
class PostSaleEntry
{
    use GuardsAgainstDoublePosting;

    public function __construct(
        private PostJournalEntry $post,
        private AccountMappingResolver $mappings,
        private PaymentAccountResolver $paymentAccounts,
    ) {}

    public function __invoke(Sale $sale): ?JournalEntry
    {
        if ($this->alreadyPosted($sale)) {
            return null;
        }

        $sale->loadMissing(['items', 'payments']);
        $storeId = (int) $sale->store_id;

        $subtotal = (string) $sale->subtotal;
        $discount = (string) $sale->discount_total;
        $tax = (string) $sale->tax_total;
        $charges = (string) $sale->additional_charges_total;
        $gross = bcadd(bcadd($subtotal, $discount, 4), $charges, 4);

        $lines = [];

        // Only rows written at checkout belong in the sale journal. Customer
        // settlement rows also carry sale_id, but have customer_id populated
        // and receive their own journal through PostCustomerPaymentEntry. A
        // delayed replay must not pull those later receipts into this entry.
        $checkoutCutoff = $sale->created_at?->copy()->addSeconds(5);
        $payments = $sale->payments
            ->filter(fn ($payment): bool => $payment->customer_id === null
                && (! $checkoutCutoff || ! $payment->created_at || $payment->created_at->lte($checkoutCutoff)))
            ->values();

        $paymentTotal = $payments->reduce(
            fn (string $total, $payment): string => bcadd($total, (string) $payment->amount, 4),
            '0',
        );

        // CompleteSale accepts a sub-minor-unit client/server discrepancy and
        // records paid_total at the authoritative server grand. The individual
        // payment rows retain the client's 4dp amount, though. Pin the final
        // tender to the grand when the same checkout tolerance classified the
        // sale as fully paid, otherwise PostJournalEntry correctly rejects a
        // harmless 0.0001-style imbalance.
        $grand = (string) $sale->grand_total;
        $paymentGap = bcsub($grand, $paymentTotal, 4);
        $withinCheckoutTolerance = bccomp($paymentGap, '-0.05', 4) >= 0
            && bccomp($paymentGap, '0.05', 4) <= 0;
        $targetPaymentTotal = $payments->isNotEmpty() && $withinCheckoutTolerance
            ? $grand
            : $paymentTotal;

        // ── Debits — assets received ───────────────────────────────────
        $methods = PaymentMethod::whereIn('id', $payments->pluck('payment_method_id')->filter()->unique()->all())
            ->get()->keyBy('id');

        $lastPaymentLine = null;
        foreach ($payments as $payment) {
            // amount is already the portion applied to the sale. The separate
            // tendered_amount/change_returned fields are receipt and drawer
            // facts; subtracting change here removes it twice.
            $amount = (string) $payment->amount;
            if (bccomp($amount, '0', 4) <= 0) {
                continue;
            }
            $method = $methods->get($payment->payment_method_id);
            $account = $method?->code === 'store_credit'
                ? $this->mappings->resolve('customer_advances', $storeId)
                : $this->paymentAccounts->resolve($method, $storeId);
            $lines[] = $this->debit($account->id, $amount, __('accounting.lines.payment'));
            $lastPaymentLine = array_key_last($lines);
        }

        if ($lastPaymentLine !== null && bccomp($targetPaymentTotal, $paymentTotal, 4) !== 0) {
            $lines[$lastPaymentLine]['debit'] = bcadd(
                (string) $lines[$lastPaymentLine]['debit'],
                bcsub($targetPaymentTotal, $paymentTotal, 4),
                4,
            );
        }

        // Derive the checkout-time receivable from immutable checkout tender
        // rows. sale.balance_due is mutable and may already have been reduced
        // by a later customer payment when this poster is replayed.
        $originalBalanceDue = bcsub($grand, $targetPaymentTotal, 4);
        if ($sale->customer_id && bccomp($originalBalanceDue, '0', 4) > 0) {
            $lines[] = $this->debit($this->mappings->id('accounts_receivable_customers', $storeId), $originalBalanceDue, __('accounting.lines.receivable'));
        }

        if (bccomp($discount, '0', 4) > 0) {
            $lines[] = $this->debit($this->mappings->id('sales_discounts', $storeId), $discount, __('accounting.lines.discount'));
        }

        // ── Credits — revenue + tax ────────────────────────────────────
        if (bccomp($gross, '0', 4) > 0) {
            $lines[] = $this->credit($this->mappings->id('sales_revenue', $storeId), $gross, __('accounting.lines.sales_revenue'));
        }
        if (bccomp($tax, '0', 4) > 0) {
            $lines[] = $this->credit($this->mappings->id('tax_output', $storeId), $tax, __('accounting.lines.tax_output'));
        }

        // ── Cost of goods sold + inventory relief ──────────────────────
        $cogs = $this->cogs($sale);
        if (bccomp($cogs, '0', 4) > 0) {
            $lines[] = $this->debit($this->mappings->id('cogs', $storeId), $cogs, __('accounting.lines.cogs'));
            $lines[] = $this->credit($this->mappings->id('inventory', $storeId), $cogs, __('accounting.lines.inventory'));
        }

        if (count($lines) < 2) {
            return null; // nothing meaningful to post (e.g. a zero-value sale)
        }

        return ($this->post)([
            'store_id' => $storeId,
            'entry_date' => $sale->sale_date,
            'source' => JournalEntry::SOURCE_SALE,
            'reference_type' => Sale::class,
            'reference_id' => $sale->id,
            'description' => __('accounting.descriptions.sale', ['number' => $sale->number]),
            'created_by' => $sale->created_by ?? $sale->cashier_id,
            'lines' => $lines,
        ]);
    }

    /** Sum quantity times sale-time cost snapshot across the sale's lines. */
    private function cogs(Sale $sale): string
    {
        $total = '0';

        foreach ($sale->items as $item) {
            $total = bcadd($total, bcmul(
                (string) $item->quantity,
                (string) ($item->unit_cost_snapshot ?? '0'),
                4,
            ), 4);
        }

        return $total;
    }

    private function alreadyPosted(Sale $sale): bool
    {
        return $this->alreadyPostedFor(JournalEntry::SOURCE_SALE, $sale);
    }
}
