<?php

namespace App\Console\Commands;

use App\Actions\Maintenance\BackfillSaleLineCosts as BackfillAction;
use Illuminate\Console\Command;

/**
 * Reconstruct `sale_items.unit_cost_snapshot` for sales rung up before the
 * cost snapshot existed, by replaying the stock ledger.
 *
 * Dry by default — run it, read the summary, then run again with --apply.
 *
 *   php artisan pos:backfill-sale-costs
 *   php artisan pos:backfill-sale-costs --apply
 *   php artisan pos:backfill-sale-costs --apply --store=6
 */
class BackfillSaleLineCosts extends Command
{
    protected $signature = 'pos:backfill-sale-costs
        {--apply : Write the costs (without this it only reports what it would do)}
        {--store= : Limit to one store id}';

    protected $description = 'Rebuild historical sale line costs from the stock ledger (fixes cost 0 / margin 100% on old sales)';

    public function handle(BackfillAction $backfill): int
    {
        $apply   = (bool) $this->option('apply');
        $storeId = $this->option('store') !== null ? (int) $this->option('store') : null;

        $this->info($apply
            ? 'Rebuilding historical sale line costs…'
            : 'DRY RUN — nothing will be written. Re-run with --apply to commit.');

        $result = $backfill($apply, $storeId);

        $this->newLine();
        $this->table(['', 'Lines'], [
            ['Zero-cost lines found', number_format($result['scanned'])],
            ['Costed',               number_format($result['filled'])],
            ['Left at zero',         number_format($result['unknown'])],
        ]);

        $this->line('  By source:');
        foreach ($result['sources'] as $source => $count) {
            if ($count > 0) {
                $this->line(sprintf('    %-10s %s', $source, number_format($count)));
            }
        }

        $this->newLine();
        $this->line('  Total COGS '.($apply ? 'written' : 'this would write').': '.$result['cogs']);

        // `catalogue` is the one source that isn't reconstructed from the
        // ledger — it's today's entered cost applied to an old sale. Worth
        // saying out loud rather than burying in the table.
        if (($result['sources']['catalogue'] ?? 0) > 0) {
            $this->newLine();
            $this->warn(sprintf(
                '  %s line(s) fell back to the product\'s current catalogue cost — those had nothing '
                .'in the stock ledger to reconstruct from, so their cost is approximate.',
                number_format($result['sources']['catalogue']),
            ));
        }

        if ($result['unknown'] > 0) {
            $this->newLine();
            $this->line(sprintf(
                '  %s line(s) had no cost source anywhere and were left at zero.',
                number_format($result['unknown']),
            ));
        }

        if (! $apply) {
            $this->newLine();
            $this->info('Nothing written. Re-run with --apply when the numbers look right.');
        }

        return self::SUCCESS;
    }
}
