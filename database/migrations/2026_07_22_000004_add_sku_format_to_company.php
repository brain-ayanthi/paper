<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Auto-generated product SKUs (docs/features/products-inventory.md).
 *
 * The template a blank SKU is filled from, configured at
 * Settings → Numbering alongside the sale / hold / refund formats.
 * NULL falls back to `SKU-{seq:05}` in Company::numberFormat('sku'), so
 * existing installs need no backfill.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('company', function (Blueprint $table) {
            $table->string('sku_format', 64)->nullable()->after('refund_number_format');
        });
    }

    public function down(): void
    {
        Schema::table('company', function (Blueprint $table) {
            $table->dropColumn('sku_format');
        });
    }
};
