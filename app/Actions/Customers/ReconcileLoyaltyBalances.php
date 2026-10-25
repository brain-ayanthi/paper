<?php

namespace App\Actions\Customers;

use App\Models\Customer;
use App\Models\CustomerCreditTransaction;
use Illuminate\Support\Facades\DB;

/**
 * Recompute `customers.loyalty_points` from the ledger and correct any
 * drift (docs/features/customers.md §11).
 *
 * The column is a running total kept on the row so a list screen doesn't
 * have to sum a ledger per customer. Every write moves both together
 * inside one transaction, so on a healthy install drift is always zero —
 * this job exists to prove that, and to heal the row if a crash or a bad
 * plugin ever splits the two.
 *
 * **The ledger wins.** It is append-only and every row records who did
 * what and what it left behind; the cached column is a convenience.
 *
 * ## The guard that matters
 *
 * Only customers with **at least one points ledger row** are reconciled.
 * A balance with no ledger behind it isn't drift — it's a figure that
 * arrived some other way (a seeded demo customer, a future importer,
 * a plugin). Summing an empty ledger gives 0, so reconciling those would
 * silently wipe real balances. Nothing in core writes `loyalty_points`
 * outside the loyalty actions today, but this job must not be the thing
 * that destroys data the day something does.
 *
 * Corrections are NOT written as ledger rows — that would move the
 * balance a second time. The row is simply set to the ledger's truth.
 *
 * Hooks:
 *   - action `loyalty.drift_corrected` → ($customer, $was, $now)
 */
class ReconcileLoyaltyBalances
{
    /**
     * @param bool $dryRun Report drift without touching anything.
     * @return array{checked:int, corrected:int, drift:int, customers:list<array{id:int, name:string, was:int, now:int}>}
     */
    public function __invoke(bool $dryRun = false): array
    {
        $checked   = 0;
        $corrected = 0;
        $drift     = 0;
        $details   = [];

        // Ledger truth per customer, in one grouped query.
        $ledger = CustomerCreditTransaction::query()
            ->points()
            ->groupBy('customer_id')
            ->selectRaw('customer_id, COALESCE(SUM(points), 0) AS total')
            ->pluck('total', 'customer_id');

        if ($ledger->isEmpty()) {
            return ['checked' => 0, 'corrected' => 0, 'drift' => 0, 'customers' => []];
        }

        Customer::query()
            ->whereIn('id', $ledger->keys())
            ->orderBy('id')
            ->chunkById(200, function ($customers) use ($ledger, $dryRun, &$checked, &$corrected, &$drift, &$details) {
                foreach ($customers as $customer) {
                    $checked++;

                    $was      = (int) ($customer->loyalty_points ?? 0);
                    $expected = (int) $ledger->get($customer->id, 0);

                    if ($was === $expected) {
                        continue;
                    }

                    $corrected++;
                    $drift += abs($expected - $was);
                    $details[] = [
                        'id'   => (int) $customer->id,
                        'name' => (string) $customer->name,
                        'was'  => $was,
                        'now'  => $expected,
                    ];

                    if ($dryRun) {
                        continue;
                    }

                    DB::transaction(function () use ($customer, $expected, $was) {
                        $locked = Customer::query()->lockForUpdate()->find($customer->id);
                        if (! $locked) {
                            return;
                        }
                        $locked->forceFill(['loyalty_points' => $expected])->save();

                        do_action('loyalty.drift_corrected', $locked, $was, $expected);
                    });
                }
            });

        return [
            'checked'   => $checked,
            'corrected' => $corrected,
            'drift'     => $drift,
            'customers' => $details,
        ];
    }
}
