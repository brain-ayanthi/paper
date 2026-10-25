<?php

namespace App\Console\Commands;

use App\Hooks\HookManager;
use Database\Seeders\DemoData\DemoVariantSupplement;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

class AddDemoProductVariants extends Command
{
    protected $signature = 'pos:demo-add-product-variants
        {--apply : Convert selected existing products and extend existing demo variants}
        {--database-confirm= : Exact configured database name}';

    protected $description = 'Add demo variants to existing products without replacing products or images';

    public function handle(): int
    {
        $database = DB::connection()->getDatabaseName();
        $stores = DB::table('stores')->whereNull('deleted_at')->where('is_active', true)->orderBy('id')->pluck('code');
        $this->line('Database: '.$database);
        $this->line('Active stores: '.$stores->implode(', '));
        $this->line('Plan: convert 18 existing products and extend six existing variant products.');
        $this->line('Final target: 24 variant products, including 20 with batch-wise stock.');
        $this->line('Existing products, child variants, images, users, settings and integration data are not deleted.');
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

        $oldHooks = app(HookManager::class);
        app()->instance(HookManager::class, new HookManager);
        config(['demo_variants.authorized' => true]);
        try {
            $result = Model::withoutEvents(
                fn () => app(DemoVariantSupplement::class)->run($this),
            );
        } finally {
            config(['demo_variants.authorized' => false]);
            app()->instance(HookManager::class, $oldHooks);
        }

        $this->info("Variant supplement complete: {$result['inserted']} children added, {$result['existing']} already present, {$result['converted']} products converted across {$result['parents']} products and {$result['stores']} stores.");

        return self::SUCCESS;
    }
}
