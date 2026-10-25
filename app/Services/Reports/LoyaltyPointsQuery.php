<?php

namespace App\Services\Reports;

use App\Models\CustomerCreditTransaction;
use App\Services\Loyalty\LoyaltyCalculator;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Query engine for the Loyalty Points report — one row per customer who
 * had any points movement in the period: earned, redeemed, expired, the
 * net change, and the balance they hold now.
 *
 * The number the owner actually cares about is in the summary: the
 * **outstanding liability** — what every unredeemed point in the system
 * would cost if it were all spent tomorrow. Points are a promise, and
 * this is the size of it.
 *
 * Store filter matches the store each movement was recorded against, so
 * a multi-store owner can see which branch is minting points. The
 * balance column is company-wide regardless — a customer holds one
 * balance, not one per store.
 */
class LoyaltyPointsQuery
{
    public function __construct(private LoyaltyCalculator $calculator) {}

    /** @return Collection<int, array<string, mixed>> */
    public function __invoke(CarbonImmutable $from, CarbonImmutable $to, ?int $storeId = null): Collection
    {
        $rows = DB::table('customer_credit_transactions as t')
            ->join('customers as c', 'c.id', '=', 't.customer_id')
            ->leftJoin('customer_groups as g', 'g.id', '=', 'c.customer_group_id')
            ->whereNull('c.deleted_at')
            ->whereIn('t.type', CustomerCreditTransaction::POINTS_TYPES)
            ->whereBetween('t.created_at', [$from->startOfDay(), $to->endOfDay()])
            ->when($storeId, fn ($q) => $q->where('t.store_id', $storeId))
            ->selectRaw('
                t.customer_id,
                MAX(c.name)          AS name,
                MAX(c.code)          AS code,
                MAX(g.name)          AS group_name,
                MAX(c.loyalty_points) AS balance,
                COALESCE(SUM(CASE WHEN t.points > 0 THEN t.points ELSE 0 END), 0) AS earned,
                COALESCE(SUM(CASE WHEN t.type = ? THEN -t.points ELSE 0 END), 0)  AS redeemed,
                COALESCE(SUM(CASE WHEN t.type = ? THEN -t.points ELSE 0 END), 0)  AS expired,
                COALESCE(SUM(t.points), 0) AS net
            ', [
                CustomerCreditTransaction::TYPE_POINTS_REDEEM,
                CustomerCreditTransaction::TYPE_POINTS_EXPIRE,
            ])
            ->groupBy('t.customer_id')
            ->orderByDesc('earned')
            ->get();

        return $rows->map(function ($r) {
            $balance = (int) $r->balance;

            return [
                'customer_id'    => $r->customer_id,
                'name'           => (string) ($r->name ?? '—'),
                'code'           => $r->code ? (string) $r->code : null,
                'group_name'     => $r->group_name ? (string) $r->group_name : null,
                'earned'         => (int) $r->earned,
                'redeemed'       => (int) $r->redeemed,
                'expired'        => (int) $r->expired,
                'net'            => (int) $r->net,
                'balance'        => $balance,
                // What this customer's unspent points would cost if
                // redeemed at today's rate.
                'balance_value'  => $this->calculator->valueOfPoints($balance),
            ];
        });
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $rows
     * @return array<string, string|int>
     */
    public function summarise(Collection $rows): array
    {
        $earned = $redeemed = $expired = 0;
        $liability = '0';

        foreach ($rows as $r) {
            $earned   += (int) $r['earned'];
            $redeemed += (int) $r['redeemed'];
            $expired  += (int) $r['expired'];
            $liability = bcadd($liability, (string) $r['balance_value'], 4);
        }

        return [
            'earned'    => $earned,
            'redeemed'  => $redeemed,
            'expired'   => $expired,
            'customers' => $rows->count(),
            'liability' => bcadd($liability, '0', 4),
        ];
    }
}
