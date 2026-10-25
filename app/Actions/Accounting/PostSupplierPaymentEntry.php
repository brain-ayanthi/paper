<?php

namespace App\Actions\Accounting;

use App\Models\JournalEntry;
use App\Models\PaymentMethod;
use App\Models\PurchasePayment;
use App\Models\Supplier;
use App\Services\Accounting\AccountMappingResolver;
use App\Services\Accounting\PaymentAccountResolver;
use App\Support\Money;
use Illuminate\Support\Collection;

/**
 * Posts the journal entry for a supplier payment — money out that clears our
 * payable (and banks any over-payment as an advance):
 *
 *   Dr  A/P — Suppliers        allocated cash + supplier credit applied
 *   Dr  Advances to Suppliers  unallocated new cash (over-payment)
 *     Cr  Cash / Bank           actual new money paid
 *     Cr  Advances to Suppliers existing advance consumed
 *
 * A purchase-linked row with no payment method is a credit application rather
 * than another cash movement. Idempotent on the batch's first row; the
 * `supplier_payment.after_create` listener wraps it so posting failure never
 * blocks the operational transaction.
 */
class PostSupplierPaymentEntry
{
    public function __construct(
        private PostJournalEntry $post,
        private AccountMappingResolver $mappings,
        private PaymentAccountResolver $paymentAccounts,
    ) {}

    /**
     * @param  list<PurchasePayment>|PurchasePayment  $paymentOrRows
     */
    public function __invoke(array|PurchasePayment $paymentOrRows, ?Supplier $supplier = null): ?JournalEntry
    {
        $rows = $paymentOrRows instanceof PurchasePayment
            ? $this->batchFor($paymentOrRows)
            : collect($paymentOrRows);

        if ($rows->isEmpty()) {
            return null;
        }

        // A failed original posting followed by a void must not create the
        // original cash movement during a later replay. There is no remaining
        // supplier-payment transaction to represent in the books.
        if ($rows->every(fn (PurchasePayment $row): bool => $row->isVoided())) {
            return null;
        }

        $rows = $rows->filter(fn (PurchasePayment $row): bool => ! $row->isVoided())->values();
        $rows->each->loadMissing('purchase');

        $supplier ??= $rows->first()->supplier()->firstOrFail();

        $refId = (int) $rows->first()->id;
        if ($this->alreadyPosted($refId)) {
            return null;
        }

        $total = $cashTotal = $allocated = $unallocated = $creditApplied = '0';
        $methodId = null;
        foreach ($rows as $r) {
            // A/P was booked in base currency when the purchase was received,
            // so settlement must clear it at that same document rate. A
            // future realised-FX slice can accept a payment-date rate and post
            // the difference explicitly; until then this preserves the
            // control-account identity instead of mixing raw currencies.
            $rate = (string) ($r->purchase?->exchange_rate_to_base ?: '1');
            $amount = Money::round(bcmul((string) $r->amount, $rate, 8));
            $total = bcadd($total, $amount, 4);
            if ($r->isCreditApplication()) {
                $creditApplied = bcadd($creditApplied, $amount, 4);
            } elseif ($r->purchase_id) {
                $allocated = bcadd($allocated, $amount, 4);
            } else {
                $unallocated = bcadd($unallocated, $amount, 4);
            }
            if (! $r->isCreditApplication()) {
                $cashTotal = bcadd($cashTotal, $amount, 4);
            }
            if (! $methodId && ! $r->isCreditApplication() && $r->payment_method_id) {
                $methodId = (int) $r->payment_method_id;
            }
        }

        if (bccomp($total, '0', 4) <= 0) {
            return null;
        }

        $storeId = (int) $rows->first()->store_id;
        $lines = [];
        $payableCleared = bcadd($allocated, $creditApplied, 4);
        if (bccomp($payableCleared, '0', 4) > 0) {
            $lines[] = ['account_id' => $this->mappings->id('accounts_payable_suppliers', $storeId), 'debit' => $payableCleared, 'credit' => 0, 'description' => __('accounting.lines.payable')];
        }
        if (bccomp($unallocated, '0', 4) > 0) {
            $lines[] = ['account_id' => $this->mappings->id('advances_to_suppliers', $storeId), 'debit' => $unallocated, 'credit' => 0, 'description' => __('accounting.lines.supplier_advance')];
        }
        if (bccomp($creditApplied, '0', 4) > 0) {
            $lines[] = ['account_id' => $this->mappings->id('advances_to_suppliers', $storeId), 'debit' => 0, 'credit' => $creditApplied, 'description' => __('accounting.lines.supplier_advance')];
        }
        if (bccomp($cashTotal, '0', 4) > 0) {
            $method = $methodId ? PaymentMethod::find($methodId) : null;
            $paymentAccount = $this->paymentAccounts->resolve($method, $storeId);
            $lines[] = ['account_id' => $paymentAccount->id, 'debit' => 0, 'credit' => $cashTotal, 'description' => __('accounting.lines.payment')];
        }

        if (count($lines) < 2) {
            return null;
        }

        return ($this->post)([
            'store_id' => $storeId,
            'entry_date' => $rows->first()->payment_date,
            'source' => JournalEntry::SOURCE_SUPPLIER_PAYMENT,
            'reference_type' => PurchasePayment::class,
            'reference_id' => $refId,
            'description' => __('accounting.descriptions.supplier_payment', ['name' => $supplier->name]),
            'created_by' => $rows->first()->created_by,
            'lines' => $lines,
        ]);
    }

    private function alreadyPosted(int $refId): bool
    {
        return JournalEntry::where('source', JournalEntry::SOURCE_SUPPLIER_PAYMENT)
            ->where('reference_type', PurchasePayment::class)
            ->where('reference_id', $refId)
            ->exists();
    }

    /** @return Collection<int, PurchasePayment> */
    private function batchFor(PurchasePayment $payment): Collection
    {
        if (! $payment->client_uuid) {
            return collect([$payment]);
        }

        return PurchasePayment::query()
            ->where('client_uuid', $payment->client_uuid)
            ->orderBy('id')
            ->get();
    }
}
