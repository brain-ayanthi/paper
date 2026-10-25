<?php

namespace App\Actions\Customers;

use App\Models\Customer;
use App\Models\CustomerCreditTransaction;
use App\Models\Sale;
use App\Models\User;

/**
 * Spend a customer's points against a sale (docs/features/customers.md §9.3).
 *
 * Deliberately dumb: it writes the ledger row and moves the balance. All
 * the deciding — how many points are allowed, what they're worth, the
 * per-sale cap — happens in {@see \App\Services\Loyalty\LoyaltyCalculator}
 * and is re-run server-side by {@see \App\Actions\Sales\CompleteSale}
 * before this is ever called. Keeping the policy in one place is what
 * stops the cashier's number and the server's number drifting apart.
 *
 * Runs inside CompleteSale's transaction; idempotent per sale.
 *
 * Hooks:
 *   - action `loyalty.redeemed` → ($customer, $points, $sale, $value)
 */
class RedeemLoyaltyPoints
{
    public function __invoke(Sale $sale, int $points, string $value, ?User $user = null): ?CustomerCreditTransaction
    {
        if (! $sale->customer_id || $points <= 0) {
            return null;
        }

        // One redeem row per sale — the local_uuid retry path must not
        // charge the customer's points twice.
        $already = CustomerCreditTransaction::query()
            ->where('type', CustomerCreditTransaction::TYPE_POINTS_REDEEM)
            ->where('reference_type', Sale::class)
            ->where('reference_id', $sale->id)
            ->first();

        if ($already) {
            return $already;
        }

        $customer = Customer::query()->lockForUpdate()->find($sale->customer_id);
        if (! $customer) {
            return null;
        }

        $balance = (int) ($customer->loyalty_points ?? 0);
        $points  = min($points, $balance);
        if ($points <= 0) {
            return null;
        }

        $balanceAfter = $balance - $points;

        $txn = CustomerCreditTransaction::create([
            'customer_id'          => $customer->id,
            'store_id'             => (int) $sale->store_id,
            'type'                 => CustomerCreditTransaction::TYPE_POINTS_REDEEM,
            'points'               => -$points,
            'balance_after_points' => $balanceAfter,
            'reference_type'       => Sale::class,
            'reference_id'         => (int) $sale->id,
            'notes'                => $sale->number,
            'created_by'           => $user?->id ?? $sale->cashier_id,
        ]);

        $customer->forceFill(['loyalty_points' => $balanceAfter])->save();

        do_action('loyalty.redeemed', $customer, $points, $sale, $value);

        return $txn;
    }
}
