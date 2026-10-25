<?php

namespace Database\Seeders\DemoData;

use Illuminate\Support\Facades\DB;
use RuntimeException;

final class DemoStoreReset
{
    public const CODES = ['MAIN', 'DT01', 'AHM1', 'OUTLET'];

    public function plan(): array
    {
        $currency = DB::table('company')->value('base_currency_code');
        if (! $currency) {
            throw new RuntimeException('An installed company base currency is required.');
        }
        $stores = DB::table('stores')->whereNull('deleted_at')->whereIn('code', self::CODES)->get();
        if ($stores->count() !== 4 || $stores->pluck('code')->unique()->count() !== 4) {
            throw new RuntimeException('Store reset requires exactly one existing MAIN, DT01, AHM1 and OUTLET.');
        }

        return ['ids' => $stores->pluck('id')->all(), 'main_id' => $stores->firstWhere('code', 'MAIN')->id, 'currency' => $currency];
    }

    public function apply(array $plan): void
    {
        if (! config('demo_rebuild.authorized') || DB::transactionLevel() === 0) {
            throw new RuntimeException('Store reset must run inside the authorized demo rebuild transaction.');
        }
        // Keep physical rows and foreign keys: never cascade-delete credentials or user defaults.
        DB::table('stores')->whereNotIn('id', $plan['ids'])->whereNull('deleted_at')
            ->update(['is_active' => false, 'is_default' => false, 'deleted_at' => now(), 'updated_at' => now()]);
        DB::table('stores')->whereNotIn('id', $plan['ids'])->where('is_default', true)
            ->update(['is_default' => false, 'updated_at' => now()]);
        DB::table('stores')->whereIn('id', $plan['ids'])
            ->update(['currency_code' => $plan['currency'], 'is_active' => true, 'is_default' => false, 'updated_at' => now()]);
        DB::table('stores')->where('id', $plan['main_id'])->update(['is_default' => true]);
    }
}
