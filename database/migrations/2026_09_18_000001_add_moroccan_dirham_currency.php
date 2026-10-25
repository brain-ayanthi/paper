<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // Preserve any existing customer-defined MAD format or active status.
        if (! DB::table('currencies')->where('code', 'MAD')->exists()) {
            DB::table('currencies')->insert([
                'code' => 'MAD',
                'name' => 'Moroccan Dirham',
                'symbol' => 'MAD',
                'decimals' => 2,
                'symbol_first' => true,
                'thousands_separator' => ',',
                'decimal_separator' => '.',
                'is_active' => true,
            ]);
        }
    }

    public function down(): void
    {
        // Retain reference data: historical transactions may use this currency.
    }
};
