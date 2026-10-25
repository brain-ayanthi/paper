<?php

namespace App\Actions\Sales;

use App\Actions\Customers\RestoreRedeemedPoints;
use App\Actions\Customers\ReverseLoyaltyPoints;
use App\Actions\Inventory\RecordStockMovement;
use App\Exceptions\SaleNotVoidable;
use App\Models\Customer;
use App\Models\CustomerCreditTransaction;
use App\Models\Sale;
use App\Models\SalePayment;
use App\Models\Shift;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Void a completed sale — reverses stock, customer balance (credit
 * sales), and shift cash totals. The sale row stays for audit with
 * `status='voided'` + `voided_at` + `voided_by` + `void_reason`.
 *
 * Eligibility (strict default — Slice 1):
 *   - status MUST be `completed`. Held / draft / partially_refunded /
 *     refunded / already-voided sales are rejected.
 *   - The sale MUST have no refunds. If a refund exists, the void
 *     attempt is rejected — the refund path is the right tool.
 *   - The sale's shift MUST still be open (when shift_id is set).
 *     Closed-shift voids would lie to the Z-report after the fact;
 *     defer to a future "manager override" slice.
 *
 * What runs atomically:
 *   1. Lock the sale + every affected stock_level + the customer (if any).
 *   2. RecordStockMovement(type='return', positive delta) per line —
 *      stock returns to inventory at the original WAC (no WAC mutation,
 *      same as refunds).
 *   3. For credit sales (sales.customer_id IS NOT NULL AND
 *      balance_due > 0): decrement customer.outstanding_balance by the
 *      remaining balance_due; zero balance_due on the sale.
 *   4. Reverse any loyalty points the sale earned.
 *   5. Set sale.status='voided', voided_at, voided_by, void_reason.
 *
 * Cash payments are NOT auto-refunded by this action — the cashier
 * physically hands the cash back from the open drawer. ComputeShiftTotals
 * filters by status='completed', so voided sales naturally drop out of
 * cash_sales on the Z-report and expected_cash decreases by the matching
 * amount on the next render.
 *
 * Hooks:
 *   - action `sale.before_void` → ($sale, $reason, $user)
 *   - action `sale.after_void`  → ($sale)
 */
class VoidSale
{
    public function __construct(
        private readonly RecordStockMovement $recordMovement,
        private readonly ReverseLoyaltyPoints $reverseLoyalty,
        private readonly RestoreRedeemedPoints $restoreRedeemed,
    ) {}

    public function __invoke(Sale $sale, ?string $reason, User $user): Sale
    {
        do_action('sale.before_void', $sale, $reason, $user);

        return DB::transaction(function () use ($sale, $reason, $user) {
            // Re-fetch with a lock + re-check inside the transaction
            // so two clicks can't race past the eligibility guards.
            $sale = Sale::query()
                ->lockForUpdate()
                ->with('items', 'returns', 'payments.paymentMethod')
                ->findOrFail($sale->id);

            $this->guard($sale);

            // Reverse stock — one positive movement per line. Mirrors
            // RecordSaleReturn's stock path: type='return', no unitCost
            // (negative-direction movements never touch WAC).
            foreach ($sale->items as $item) {
                ($this->recordMovement)(
                    storeId:       (int) $sale->store_id,
                    productId:     (int) $item->product_id,
                    variantId:     $item->variant_id,
                    batchId:       $item->batch_id,
                    quantityDelta: (string) $item->quantity,
                    type:          'return',
                    referenceType: Sale::class,
                    referenceId:   (int) $sale->id,
                    unitCost:      null,
                    notes:         "Void of sale {$sale->number}",
                    createdBy:     $user->id,
                );
            }

            // Reverse customer outstanding for credit sales. The unpaid
            // portion is what the customer no longer owes — paid_total
            // stays as-is because the cash already moved.
            if ($sale->customer_id) {
                $customer = Customer::query()
                    ->lockForUpdate()
                    ->find($sale->customer_id);
                if ($customer) {
                    $nextOutstanding = (string) ($customer->outstanding_balance ?? '0');
                    if (bccomp((string) $sale->balance_due, '0', 4) > 0) {
                        $nextOutstanding = bcsub($nextOutstanding, (string) $sale->balance_due, 4);
                    }

                    $storeCreditRestored = '0.0000';
                    foreach ($sale->payments as $payment) {
                        if ($payment->paymentMethod?->code === 'store_credit') {
                            $storeCreditRestored = bcadd($storeCreditRestored, (string) $payment->amount, 4);
                        }
                    }
                    $nextStoreCredit = bcadd(
                        (string) ($customer->store_credit_balance ?? '0'),
                        $storeCreditRestored,
                        4,
                    );

                    $customer->forceFill([
                        'outstanding_balance'   => $nextOutstanding,
                        'store_credit_balance' => $nextStoreCredit,
                    ])->save();

                    if (bccomp($storeCreditRestored, '0', 4) > 0) {
                        CustomerCreditTransaction::create([
                            'customer_id'          => $customer->id,
                            'store_id'             => $sale->store_id,
                            'type'                 => CustomerCreditTransaction::TYPE_CREDIT_ADJUST,
                            'amount'               => $storeCreditRestored,
                            'balance_after_amount' => $nextStoreCredit,
                            'reference_type'       => Sale::class,
                            'reference_id'         => $sale->id,
                            'notes'                => __('cashier.store_credit.void_restore', ['number' => $sale->number]),
                            'created_by'           => $user->id,
                        ]);
                    }
                }
            }

            // Undo the sale's loyalty effects in both directions: take back
            // what it earned (clamped to the balance — see the action's
            // docblock) and give back what it spent.
            ($this->reverseLoyalty)($sale, $user);
            ($this->restoreRedeemed)($sale, $user);

            $sale->forceFill([
                'status'      => Sale::STATUS_VOIDED,
                'voided_at'   => now(),
                'voided_by'   => $user->id,
                'void_reason' => $reason,
                'balance_due' => '0.0000',
            ])->save();

            $fresh = $sale->fresh();
            do_action('sale.after_void', $fresh);

            return $fresh;
        });
    }

    /**
     * Throws SaleNotVoidable when the sale's current state forbids the
     * void. Each reason maps to a localised message key under
     * `sales.errors.not_voidable.<reason>`.
     */
    private function guard(Sale $sale): void
    {
        if ($sale->status !== Sale::STATUS_COMPLETED) {
            throw new SaleNotVoidable($sale, 'status_'.$sale->status);
        }
        if ($sale->returns->isNotEmpty()) {
            throw new SaleNotVoidable($sale, 'has_returns');
        }
        if ($sale->shift_id) {
            $shift = Shift::query()->find($sale->shift_id);
            if ($shift && ! $shift->isOpen()) {
                throw new SaleNotVoidable($sale, 'shift_closed');
            }
        }

        // A credit sale that has since been SETTLED cannot be voided
        // (defect #328). The void reverses `outstanding_balance` by the
        // sale's unpaid portion — which is zero once it is settled — so the
        // customer's balance was left carrying a debt for a sale that no
        // longer exists, while the settlement payment stayed on the books
        // pointing at a voided sale. Accounts receivable ends up wrong in two
        // directions at once and there is no self-service way back.
        //
        // Deliberately narrower than "has any payment": a sale paid IN FULL at
        // the till has payments too, and voiding that is the ordinary
        // same-shift mistake-correction this action exists for. What is
        // refused is a payment taken AFTER the sale — a settlement — which is
        // what `sale_payments.paid_at > sales.created_at` with a customer
        // attached actually means. The refund flow is the right tool there.
        if ($sale->customer_id && $this->hasSettlementPayment($sale)) {
            throw new SaleNotVoidable($sale, 'has_settlement');
        }
    }

    /**
     * Has money been taken against this sale after it was rung up? That is a
     * settlement of a credit sale rather than a tender at the till.
     */
    private function hasSettlementPayment(Sale $sale): bool
    {
        return SalePayment::query()
            ->where('sale_id', $sale->id)
            ->whereNotNull('paid_at')
            // One minute of slack so a slow checkout — where the tender lands
            // a few seconds after the sale row — is never mistaken for a
            // later settlement.
            ->where('paid_at', '>', $sale->created_at?->copy()->addMinute() ?? now())
            ->exists();
    }
}
