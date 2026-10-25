<?php

namespace App\Actions\Customers;

use App\Models\Customer;
use App\Models\CustomerCreditTransaction;
use Illuminate\Support\Facades\DB;

/**
 * Expire points that were earned too long ago (docs/features/customers.md §9.4).
 *
 * ## Why this needs a FIFO walk
 *
 * `expires_at` lives on the EARN row, but nothing else does: a redemption
 * spends "points", not specific points. So "how many expired points does
 * this customer still hold?" can only be answered by replaying the ledger
 * and deciding which earnings each spend consumed.
 *
 * We consume **oldest-first**, which is both the intuitive reading ("your
 * oldest points go first") and the one that favours the customer — points
 * closest to expiring get spent before they lapse.
 *
 * The walk:
 *   1. Every positive row opens a lot carrying its own `expires_at`
 *      (manual top-ups have none, so they never lapse).
 *   2. Every negative row (redeem, reverse, a previous expiry, a manual
 *      removal) drains lots oldest-first.
 *   3. Whatever is left in lots whose `expires_at` has passed is what
 *      expires now.
 *
 * That makes the action naturally idempotent: the `points_expire` row it
 * writes is itself a negative row, so the next run's walk sees the lapsed
 * lot already drained and expires nothing twice.
 *
 * One `points_expire` row per customer per run, so the ledger reads as
 * "500 points expired on 1 Aug" rather than a scatter of lot-sized rows.
 *
 * Hooks:
 *   - action `loyalty.expired` → ($customer, $points)
 */
class ExpireLoyaltyPoints
{
    /**
     * @return array{customers:int, points:int} What the run did — the
     *         command prints it and the scheduler log keeps it.
     */
    public function __invoke(): array
    {
        $now = now('UTC');

        // Only customers who (a) hold points and (b) have at least one
        // earn row already past its expiry are worth walking.
        $candidates = CustomerCreditTransaction::query()
            ->where('type', CustomerCreditTransaction::TYPE_POINTS_EARN)
            ->whereNotNull('expires_at')
            ->where('expires_at', '<=', $now)
            ->distinct()
            ->pluck('customer_id');

        $touched = 0;
        $total   = 0;

        Customer::query()
            ->whereIn('id', $candidates)
            ->where('loyalty_points', '>', 0)
            ->orderBy('id')
            ->chunkById(200, function ($customers) use (&$touched, &$total, $now) {
                foreach ($customers as $customer) {
                    $expired = $this->expireFor($customer, $now);
                    if ($expired > 0) {
                        $touched++;
                        $total += $expired;
                    }
                }
            });

        return ['customers' => $touched, 'points' => $total];
    }

    /** Expire one customer's lapsed points. Returns how many went. */
    private function expireFor(Customer $customer, \DateTimeInterface $now): int
    {
        return DB::transaction(function () use ($customer, $now) {
            $customer = Customer::query()->lockForUpdate()->find($customer->id);
            if (! $customer) {
                return 0;
            }

            $rows = CustomerCreditTransaction::query()
                ->where('customer_id', $customer->id)
                ->points()
                ->orderBy('id')
                ->get(['points', 'expires_at']);

            $expired = $this->lapsedPoints($rows, $now);
            if ($expired <= 0) {
                return 0;
            }

            // Never take more than the customer actually holds — a drifted
            // cached balance must not be able to drive this negative.
            $balance = (int) ($customer->loyalty_points ?? 0);
            $expired = min($expired, $balance);
            if ($expired <= 0) {
                return 0;
            }

            $balanceAfter = $balance - $expired;

            CustomerCreditTransaction::create([
                'customer_id'          => $customer->id,
                'store_id'             => $this->storeIdFor($customer),
                'type'                 => CustomerCreditTransaction::TYPE_POINTS_EXPIRE,
                'points'               => -$expired,
                'balance_after_points' => $balanceAfter,
                'notes'                => __('customers.loyalty.expired_note'),
            ]);

            $customer->forceFill(['loyalty_points' => $balanceAfter])->save();

            do_action('loyalty.expired', $customer, $expired);

            return $expired;
        });
    }

    /**
     * Replay the ledger and return how many points sit in already-lapsed
     * lots. See the class docblock for the consumption rule.
     *
     * @param \Illuminate\Support\Collection<int, CustomerCreditTransaction> $rows
     */
    private function lapsedPoints($rows, \DateTimeInterface $now): int
    {
        /** @var list<array{remaining:int, expires_at:?\DateTimeInterface}> $lots */
        $lots = [];

        foreach ($rows as $row) {
            $points = (int) $row->points;

            if ($points > 0) {
                $lots[] = ['remaining' => $points, 'expires_at' => $row->expiresAtUtc()];
                continue;
            }

            // Drain oldest-first.
            $toSpend = -$points;
            foreach ($lots as $i => $lot) {
                if ($toSpend <= 0) {
                    break;
                }
                $take = min($lot['remaining'], $toSpend);
                $lots[$i]['remaining'] -= $take;
                $toSpend -= $take;
            }
            // A leftover here means spends exceeded earnings — drift, not
            // an expiry problem. The reconciliation job owns that.
        }

        $expired = 0;
        foreach ($lots as $lot) {
            if ($lot['remaining'] > 0
                && $lot['expires_at'] !== null
                && $lot['expires_at'] <= $now) {
                $expired += $lot['remaining'];
            }
        }

        return $expired;
    }

    /** `store_id` is NOT NULL; an expiry has no till behind it. */
    private function storeIdFor(Customer $customer): int
    {
        return (int) ($customer->first_store_id
            ?? \App\Models\Store::query()->where('is_active', true)->orderBy('id')->value('id'));
    }
}
