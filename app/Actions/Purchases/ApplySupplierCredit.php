<?php

namespace App\Actions\Purchases;

use App\Models\Purchase;
use App\Models\PurchasePayment;
use App\Models\Supplier;
use App\Models\User;
use Illuminate\Support\Str;

/**
 * Apply an existing unallocated supplier advance to one received purchase.
 *
 * A credit application is deliberately represented by a purchase-linked
 * PurchasePayment with no payment method. No money leaves cash/bank and the
 * supplier's net outstanding balance does not move: receiving the purchase
 * already increased that net balance, while this row only consumes part of
 * the previously recorded advance and closes the document balance.
 *
 * Callers must hold the supplier and purchase row locks inside a transaction.
 */
class ApplySupplierCredit
{
    public function availableFor(Supplier $supplier): string
    {
        $unallocated = PurchasePayment::query()
            ->active()
            ->where('supplier_id', $supplier->id)
            ->whereNull('purchase_id')
            ->sum('amount');

        $applied = PurchasePayment::query()
            ->active()
            ->where('supplier_id', $supplier->id)
            ->whereNotNull('purchase_id')
            ->whereNull('payment_method_id')
            ->sum('amount');

        $available = bcsub((string) ($unallocated ?: '0'), (string) ($applied ?: '0'), 4);

        return bccomp($available, '0', 4) > 0 ? $available : '0.0000';
    }

    public function __invoke(
        Purchase $purchase,
        Supplier $supplier,
        ?string $maximum = null,
        ?string $paymentDate = null,
        ?User $user = null,
        ?string $clientUuid = null,
    ): ?PurchasePayment {
        if ((int) $purchase->supplier_id !== (int) $supplier->id
            || ! in_array($purchase->status, [Purchase::STATUS_RECEIVED, Purchase::STATUS_PARTIALLY_PAID], true)
            || bccomp((string) $purchase->balance_due, '0', 4) <= 0) {
            return null;
        }

        $amount = $this->availableFor($supplier);
        if ($maximum !== null && bccomp($amount, $maximum, 4) > 0) {
            $amount = $maximum;
        }
        if (bccomp($amount, (string) $purchase->balance_due, 4) > 0) {
            $amount = (string) $purchase->balance_due;
        }
        if (bccomp($amount, '0', 4) <= 0) {
            return null;
        }

        $newPaid = bcadd((string) $purchase->paid_total, $amount, 4);
        $newBalance = bcsub((string) $purchase->balance_due, $amount, 4);
        $newStatus = bccomp($newBalance, '0', 4) === 0
            ? Purchase::STATUS_PAID
            : Purchase::STATUS_PARTIALLY_PAID;

        $row = PurchasePayment::create([
            'purchase_id' => $purchase->id,
            'supplier_id' => $supplier->id,
            'store_id' => $purchase->store_id,
            'payment_method_id' => null,
            'payment_date' => $paymentDate ?: now()->toDateString(),
            'amount' => $amount,
            'currency_code' => $purchase->currency_code,
            'reference' => null,
            'client_uuid' => $clientUuid ?: (string) Str::uuid(),
            'notes' => __('supplier_payments.credit_application.note', ['number' => $purchase->number]),
            'created_by' => $user?->id,
        ]);

        $purchase->forceFill([
            'paid_total' => $newPaid,
            'balance_due' => $newBalance,
            'status' => $newStatus,
            'updated_by' => $user?->id,
        ])->save();

        return $row;
    }
}
