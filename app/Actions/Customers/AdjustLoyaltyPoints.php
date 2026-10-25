<?php

namespace App\Actions\Customers;

use App\Models\Customer;
use App\Models\CustomerCreditTransaction;
use App\Models\Store;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Manual points adjustment by a manager (docs/features/customers.md §9.5).
 *
 * The escape hatch every loyalty programme needs: goodwill after a
 * complaint, a promotion the rules can't express, or fixing a mistake.
 * Always carries a reason — an unexplained balance change is exactly what
 * an owner will later want to audit.
 *
 * `$points` is always POSITIVE; `$direction` says which way it moves, so
 * no screen has to accept a negative number (see the negative-numbers
 * convention). A removal is clamped to the balance — points the customer
 * doesn't have can't be taken.
 *
 * Permission: `customers.loyalty_manage`, enforced by the controller.
 *
 * Hooks:
 *   - action `loyalty.adjusted` → ($customer, $delta, $user)
 */
class AdjustLoyaltyPoints
{
    public const DIRECTION_ADD    = 'add';
    public const DIRECTION_REMOVE = 'remove';

    public function __invoke(
        Customer $customer,
        int $points,
        string $direction,
        string $reason,
        ?int $storeId = null,
        ?User $user = null,
    ): CustomerCreditTransaction {
        if ($points <= 0) {
            throw new RuntimeException(__('customers.loyalty.errors.points_positive'));
        }

        return DB::transaction(function () use ($customer, $points, $direction, $reason, $storeId, $user) {
            $customer = Customer::query()->lockForUpdate()->findOrFail($customer->id);

            $balance = (int) ($customer->loyalty_points ?? 0);

            if ($direction === self::DIRECTION_REMOVE) {
                // Never drive the balance negative — a shopkeeper reading
                // "-40 points" has no way to act on it.
                $points = min($points, $balance);
                if ($points <= 0) {
                    throw new RuntimeException(__('customers.loyalty.errors.nothing_to_remove'));
                }
                $delta = -$points;
            } else {
                $delta = $points;
            }

            $balanceAfter = $balance + $delta;

            $txn = CustomerCreditTransaction::create([
                'customer_id'          => $customer->id,
                'store_id'             => $this->resolveStoreId($storeId, $customer),
                'type'                 => CustomerCreditTransaction::TYPE_POINTS_ADJUST,
                'points'               => $delta,
                'balance_after_points' => $balanceAfter,
                'notes'                => $reason,
                'created_by'           => $user?->id,
            ]);

            $customer->forceFill(['loyalty_points' => $balanceAfter])->save();

            do_action('loyalty.adjusted', $customer, $delta, $user);

            return $txn;
        });
    }

    /**
     * `customer_credit_transactions.store_id` is NOT NULL, but an
     * adjustment is made from the back office where there may be no
     * active store in session. Fall back to where the customer was first
     * seen, then to any active store.
     */
    private function resolveStoreId(?int $storeId, Customer $customer): int
    {
        return (int) ($storeId
            ?? current_store_id()
            ?? $customer->first_store_id
            ?? Store::query()->where('is_active', true)->orderBy('id')->value('id'));
    }
}
