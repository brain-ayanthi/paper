<?php

namespace App\Console\Commands;

use App\Hooks\HookManager;
use Database\Seeders\DemoData\DemoStockTopUp;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

class TopUpDemoStock extends Command
{
    protected $signature = 'pos:demo-top-up-stock
        {--apply : Raise eligible stock levels to the target}
        {--database-confirm= : Exact configured database name}
        {--target=5000 : Target quantity per store and sellable variant}';

    protected $description = 'Raise normal demo stock while preserving current low and out-of-stock examples';

    public function handle(): int
    {
        $database = DB::connection()->getDatabaseName();
        $target = (string) $this->option('target');
        $service = app(DemoStockTopUp::class);
        $plan = $service->plan($target);
        $this->line('Database: '.$database);
        $this->line('Target quantity: '.$target.' per store and sellable variant');
        $this->line("Plan: {$plan['eligible']} levels to top up; {$plan['low']} low and {$plan['out']} out-of-stock levels preserved; {$plan['already_high']} already at target.");
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
        config(['demo_stock_top_up.authorized' => true]);
        try {
            $result = Model::withoutEvents(fn () => $service->run($target, $this));
        } finally {
            config(['demo_stock_top_up.authorized' => false]);
            app()->instance(HookManager::class, $oldHooks);
        }

        $this->info("Demo stock top-up complete: {$result['topped_up']} levels updated and {$result['quantity_added']} units added. Low/out-of-stock examples were unchanged.");

        return self::SUCCESS;
    }
}
