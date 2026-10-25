<?php

namespace App\Console\Commands;

use Database\Seeders\DemoData\DemoStoreReset;
use Database\Seeders\DemoData\DemoTablePolicy;
use Database\Seeders\RealisticDemoSeeder;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class DemoRebuild extends Command
{
    protected $signature = 'pos:demo-rebuild {--reset-stores : Keep MAIN, DT01, AHM1 and OUTLET in company currency; archive other stores} {--apply : Replace demo transactions after taking a backup} {--database-confirm= : Exact configured database name} {--price-scale= : Illustrative price multiplier for the configured currency}';

    protected $description = 'Preview or rebuild realistic demo data while preserving users, settings and credentials';

    public function handle(): int
    {
        $plan = (new DemoTablePolicy)->plan();
        $this->line('Database: '.DB::connection()->getDatabaseName());
        $storePlan = $this->option('reset-stores') ? (new DemoStoreReset)->plan() : null;
        $preserve = $storePlan ? array_diff($plan['preserve'], ['stores']) : $plan['preserve'];
        $this->line('Preserve unchanged: '.implode(', ', $preserve));
        if ($storePlan) {
            $this->line('Stores: keep MAIN, DT01, AHM1, OUTLET in '.$storePlan['currency'].'; MAIN is default. Archive all others, retaining linked credentials and user assignments.');
        }
        $this->line('Clear in dependency order: '.implode(', ', $plan['reset']));
        if (! $this->option('apply')) {
            $this->info('Preview only. No rows changed.');

            return self::SUCCESS;
        }
        if (! pos_is_demo() || ! app()->isDownForMaintenance()) {
            $this->error('Enable demo mode and put the application in maintenance mode before rebuilding. Stop workers and scheduled tasks too.');

            return self::FAILURE;
        }
        if ($this->option('database-confirm') !== DB::connection()->getDatabaseName()) {
            $this->error('The database confirmation must exactly match the displayed database name.');

            return self::FAILURE;
        }
        $currency = DB::table('company')->value('base_currency_code');
        $scale = $this->option('price-scale') ?: ($currency === 'USD' ? '1' : null);
        if ($scale === null) {
            $this->error('Supply --price-scale for this currency. It is a demo pricing multiplier, not a currency conversion service.');

            return self::FAILURE;
        }
        config(['demo_rebuild.authorized' => true, 'demo_rebuild.price_scale' => $scale, 'demo_rebuild.reset_stores' => (bool) $this->option('reset-stores')]);
        try {
            app(RealisticDemoSeeder::class)->setCommand($this)->run();
        } finally {
            config(['demo_rebuild.authorized' => false, 'demo_rebuild.reset_stores' => false]);
        }
        $this->info('Demo data rebuilt and validated. Users, settings, and integration configuration are unchanged.');

        return self::SUCCESS;
    }
}
