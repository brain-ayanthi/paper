<?php

namespace App\Actions\Customers;

use App\Models\Customer;
use App\Models\CustomerCreditTransaction;
use App\Models\Sale;
use App\Models\User;

/**
 * Claw back points a sale earned, when that sale is voided or refunded.
 *
 * Not in the spec, but the program is broken without it: buy, collect
 * points, refund, keep the points, repeat. Runs inside the caller's
 * transaction ({@see \App\Actions\Sales\VoidSale},
 * {@see \App\Actions\Sales\RecordSaleReturn}) so the reversal commits with
 * the void/refund or not at all.
 *
 * How much is reversed:
 *   - Base = points still outstanding from this sale (its earn row minus
 *     anything already reversed) — so two partial refunds can't together
 *     take back more than was ever given.
 *   - Scaled by `$portion` for a partial refund (refund grand ÷ sale
 *     grand), rounded UP so the store never eats the rounding.
 *   - Finally CLAMPED to the customer's current balance. A customer who
 *     already spent the points keeps the difference rather than being
 *     driven to a negative balance — negative point balances confuse
 *     shopkeepers and would make the ledger disagree with the daily
 *     reconciliation. The ledger records what actually moved.
 *
 * Hooks:
 *   - action `loyalty.reversed` → ($customer, $points, $sale)
 */
class ReverseLoyaltyPoints
{
    /**
     * @param string|null $portion Ratio of the sale being reversed, as a
     *                             decimal-string in [0,1]. Null = all of it.
     */
    public function __invoke(Sale $sale, ?User $user = null, ?string $portion = null): ?CustomerCreditTransaction
    {
        if (! $sale->customer_id) {
            return null;
        }

        $rows = CustomerCreditTransaction::query()
            ->whereIn('type', [
                CustomerCreditTransaction::TYPE_POINTS_EARN,
                CustomerCreditTransaction::TYPE_POINTS_REVERSE,
            ])
            ->where('reference_type', Sale::class)
            ->where('reference_id', $sale->id)
            ->get();

        // Earn rows are positive, reverse rows negative — the sum is
        // what this sale still has outstanding.
        $outstanding = (int) $rows->sum('points');
        if ($outstanding <= 0) {
            return null;
        }

        $toReverse = $outstanding;

        if ($portion !== null && bccomp($portion, '1', 8) < 0) {
            if (bccomp($portion, '0', 8) <= 0) {
                return null;
            }
            $exact = bcmul((string) $outstanding, $portion, 8);
            $floor = bcadd($exact, '0', 0);
            // Round up — a half-earned point taken back in full beats
            // leaving fractions of value behind on every partial refund.
            $toReverse = (int) (bccomp($exact, $floor, 8) > 0 ? bcadd($floor, '1', 0) : $floor);
            $toReverse = min($toReverse, $outstanding);
        }

        if ($toReverse <= 0) {
            return null;
        }

        $customer = Customer::query()->lockForUpdate()->find($sale->customer_id);
        if (! $customer) {
            return null;
        }

        $balance   = (int) ($customer->loyalty_points ?? 0);
        $toReverse = min($toReverse, $balance);
        if ($toReverse <= 0) {
            return null;
        }

        $balanceAfter = $balance - $toReverse;

        $txn = CustomerCreditTransaction::create([
            'customer_id'          => $customer->id,
            'store_id'             => (int) $sale->store_id,
            'type'                 => CustomerCreditTransaction::TYPE_POINTS_REVERSE,
            'points'               => -$toReverse,
            'balance_after_points' => $balanceAfter,
            'reference_type'       => Sale::class,
            'reference_id'         => (int) $sale->id,
            'notes'                => $sale->number,
            'created_by'           => $user?->id,
        ]);

        $customer->forceFill(['loyalty_points' => $balanceAfter])->save();

        do_action('loyalty.reversed', $customer, $toReverse, $sale);

        return $txn;
    }
}
