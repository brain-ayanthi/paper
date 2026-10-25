<?php

namespace App\Actions\Customers;

use App\Models\Customer;
use App\Models\CustomerCreditTransaction;
use App\Models\Sale;
use App\Models\User;

/**
 * Give back points a voided sale had redeemed.
 *
 * Voiding says the sale never happened, so the customer should be left
 * exactly as they were: the points they spent come back, and (via
 * {@see ReverseLoyaltyPoints}) the points they earned go away.
 *
 * **Refunds deliberately do NOT restore redeemed points in v1.0.** A
 * refund gives back the money that was actually paid, which already
 * reflects the points discount; restoring the points on top would hand
 * the customer the value twice. Partial refunds make any split of the
 * redemption ambiguous, so we take the simple, defensible line and
 * document it rather than guessing.
 *
 * Runs inside VoidSale's transaction; idempotent per sale.
 *
 * Hooks:
 *   - action `loyalty.redemption_restored` → ($customer, $points, $sale)
 */
class RestoreRedeemedPoints
{
    public function __invoke(Sale $sale, ?User $user = null): ?CustomerCreditTransaction
    {
        $points = (int) ($sale->loyalty_points_redeemed ?? 0);

        if (! $sale->customer_id || $points <= 0) {
            return null;
        }

        $already = CustomerCreditTransaction::query()
            ->where('type', CustomerCreditTransaction::TYPE_POINTS_ADJUST)
            ->where('reference_type', Sale::class)
            ->where('reference_id', $sale->id)
            ->exists();

        if ($already) {
            return null;
        }

        $customer = Customer::query()->lockForUpdate()->find($sale->customer_id);
        if (! $customer) {
            return null;
        }

        $balanceAfter = (int) ($customer->loyalty_points ?? 0) + $points;

        $txn = CustomerCreditTransaction::create([
            'customer_id'          => $customer->id,
            'store_id'             => (int) $sale->store_id,
            'type'                 => CustomerCreditTransaction::TYPE_POINTS_ADJUST,
            'points'               => $points,
            'balance_after_points' => $balanceAfter,
            'reference_type'       => Sale::class,
            'reference_id'         => (int) $sale->id,
            'notes'                => $sale->number,
            'created_by'           => $user?->id,
        ]);

        $customer->forceFill(['loyalty_points' => $balanceAfter])->save();

        do_action('loyalty.redemption_restored', $customer, $points, $sale);

        return $txn;
    }
}
