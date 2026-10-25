<?php

namespace App\Console\Commands;

use App\Hooks\HookManager;
use Database\Seeders\DemoData\DemoPharmacySupplement;
use Database\Seeders\DemoData\RealisticPharmacyCatalog;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

class AddDemoPharmacyProducts extends Command
{
    protected $signature = 'pos:demo-add-pharmacy-products
        {--apply : Add the pharmacy supplement}
        {--database-confirm= : Exact configured database name}
        {--price-scale= : Illustrative multiplier for the configured currency}';

    protected $description = 'Add pharmacy demo products without replacing existing products or images';

    public function handle(): int
    {
        $database = DB::connection()->getDatabaseName();
        $stores = DB::table('stores')->whereNull('deleted_at')->where('is_active', true)->orderBy('id')->pluck('code');
        $this->line('Database: '.$database);
        $this->line('Active stores: '.$stores->implode(', '));
        $this->line('Pharmacy products: '.count(RealisticPharmacyCatalog::products()));
        $this->line('Existing products, images, users, settings and integration data are not updated or deleted.');
        if (! $this->option('apply')) {
            $this->info('Preview only. No rows changed.');

            return self::SUCCESS;
        }
        if (! pos_is_demo() || ! app()->isDownForMaintenance()) {
            $this->error('Enable demo mode and put the application in maintenance mode. Stop workers and scheduled tasks too.');

            return self::FAILURE;
        }
        if ($this->option('database-confirm') !== $database) {
            $this->error('The database confirmation must exactly match the displayed database name.');

            return self::FAILURE;
        }
        $currency = DB::table('company')->value('base_currency_code');
        $scale = $this->option('price-scale') ?: ($currency === 'USD' ? '1' : null);
        if ($scale === null) {
            $this->error('Supply --price-scale for this currency. It is an illustrative multiplier, not a currency conversion service.');

            return self::FAILURE;
        }

        $oldHooks = app(HookManager::class);
        app()->instance(HookManager::class, new HookManager);
        config(['demo_pharmacy.authorized' => true, 'demo_pharmacy.price_scale' => $scale]);
        try {
            $result = Model::withoutEvents(
                fn () => app(DemoPharmacySupplement::class)->run($this),
            );
        } finally {
            config(['demo_pharmacy.authorized' => false]);
            app()->instance(HookManager::class, $oldHooks);
        }

        $this->info("Pharmacy supplement complete: {$result['inserted']} added, {$result['existing']} already present across {$result['stores']} stores.");

        return self::SUCCESS;
    }
}
