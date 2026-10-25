<?php

namespace App\Actions\Customers;

use App\Models\Customer;
use App\Models\CustomerCreditTransaction;
use App\Models\Sale;
use App\Models\User;
use App\Services\Loyalty\LoyaltyCalculator;

/**
 * Award loyalty points for a completed sale (docs/features/customers.md §9.2).
 *
 * Called from inside {@see \App\Actions\Sales\CompleteSale}'s transaction so
 * the points, the ledger row, and the sale itself commit together — an
 * offline sale that syncs days later earns exactly the same way, because
 * the server does the computing, never the browser.
 *
 * No-ops (returns null) for: walk-in sales, a disabled program, a tier
 * with a 0 multiplier, an amount too small to earn a whole point, and a
 * sale that already has an earn row (idempotent — CompleteSale's
 * `local_uuid` retry path can call this twice).
 *
 * Hooks:
 *   - action `loyalty.earned` → ($customer, $points, $sale)
 */
class EarnLoyaltyPoints
{
    public function __construct(
        private readonly LoyaltyCalculator $calculator,
    ) {}

    public function __invoke(Sale $sale, ?User $user = null): ?CustomerCreditTransaction
    {
        if (! $sale->customer_id || ! $this->calculator->isEnabled()) {
            return null;
        }

        // Idempotency — one earn row per sale, ever.
        $already = CustomerCreditTransaction::query()
            ->where('type', CustomerCreditTransaction::TYPE_POINTS_EARN)
            ->where('reference_type', Sale::class)
            ->where('reference_id', $sale->id)
            ->first();

        if ($already) {
            return $already;
        }

        // Lock the customer: the balance is a running total, and two
        // terminals ringing up the same customer must serialize. Re-entrant
        // inside CompleteSale's transaction, which already holds this lock.
        $customer = Customer::query()->lockForUpdate()->find($sale->customer_id);
        if (! $customer) {
            return null;
        }

        $points = $this->calculator->pointsForSale($sale, $customer->loadMissing('group'));
        if ($points <= 0) {
            return null;
        }

        $balanceAfter = (int) ($customer->loyalty_points ?? 0) + $points;

        $txn = CustomerCreditTransaction::create([
            'customer_id'          => $customer->id,
            'store_id'             => (int) $sale->store_id,
            'type'                 => CustomerCreditTransaction::TYPE_POINTS_EARN,
            'points'               => $points,
            'balance_after_points' => $balanceAfter,
            'reference_type'       => Sale::class,
            'reference_id'         => (int) $sale->id,
            'expires_at'           => $this->calculator->expiresAt(),
            'notes'                => $sale->number,
            'created_by'           => $user?->id ?? $sale->cashier_id,
        ]);

        $customer->forceFill(['loyalty_points' => $balanceAfter])->save();

        do_action('loyalty.earned', $customer, $points, $sale);

        return $txn;
    }
}
