<?php

namespace App\Console\Commands;

use App\Actions\Customers\ExpireLoyaltyPoints;
use App\Actions\Customers\ReconcileLoyaltyBalances;
use Illuminate\Console\Command;

/**
 * Nightly loyalty housekeeping (docs/features/customers.md §9.4 + §11):
 *
 *   1. Expire points past their `expires_at` (no-op when the programme
 *      has no expiry configured — nothing carries an expiry date).
 *   2. Reconcile `customers.loyalty_points` against the ledger and heal
 *      any drift. Zero drift on a healthy install.
 *
 * Both are safe to re-run: expiry is idempotent by construction (its own
 * negative row consumes the lapsed lot on the next walk), and
 * reconciliation converges to the ledger.
 *
 * Run with:  php artisan pos:loyalty-maintenance
 * Dry-run:   php artisan pos:loyalty-maintenance --dry-run   (reconcile only)
 * Skip one:  --no-expire  /  --no-reconcile
 */
class LoyaltyMaintenance extends Command
{
    protected $signature = 'pos:loyalty-maintenance
        {--dry-run : Report drift without correcting it (skips expiry entirely)}
        {--no-expire : Skip the expiry pass}
        {--no-reconcile : Skip the reconciliation pass}';

    protected $description = 'Expire lapsed loyalty points and reconcile point balances against the ledger.';

    public function handle(ExpireLoyaltyPoints $expire, ReconcileLoyaltyBalances $reconcile): int
    {
        $dry = (bool) $this->option('dry-run');

        if ($dry) {
            $this->warn('Dry-run — nothing will be written.');
        }

        // Expiry has no meaningful dry-run (it would have to simulate the
        // ledger rows it writes), so a dry run reports drift only.
        if (! $this->option('no-expire') && ! $dry) {
            $result = $expire();
            $this->info(sprintf(
                'Expiry: %d points expired across %d customer(s).',
                $result['points'],
                $result['customers'],
            ));
        }

        if (! $this->option('no-reconcile')) {
            $result = $reconcile($dry);

            if ($result['corrected'] === 0) {
                $this->info(sprintf('Reconcile: %d customer(s) checked, no drift.', $result['checked']));
            } else {
                $this->warn(sprintf(
                    'Reconcile: %d of %d customer(s) drifted by %d point(s) in total.',
                    $result['corrected'],
                    $result['checked'],
                    $result['drift'],
                ));

                foreach ($result['customers'] as $row) {
                    $this->line(sprintf(
                        '  #%d %s: %d → %d',
                        $row['id'],
                        $row['name'],
                        $row['was'],
                        $row['now'],
                    ));
                }

                // Drift means something wrote a balance outside the ledger.
                // The owner should know even if they never read cron output.
                if (! $dry) {
                    notify_admins(
                        'loyalty.drift',
                        __('customers.loyalty.drift.title'),
                        __('customers.loyalty.drift.message', [
                            'count' => $result['corrected'],
                            'drift' => $result['drift'],
                        ]),
                        'star',
                        null,
                        'customers.loyalty_manage',
                    );
                }
            }
        }

        return self::SUCCESS;
    }
}
