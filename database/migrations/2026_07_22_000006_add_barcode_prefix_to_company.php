<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * In-store barcode prefix for auto-generated product barcodes
 * (docs/features/products-inventory.md §10).
 *
 * Non-standard items — loose goods, in-house products, anything without a
 * manufacturer barcode — get a valid EAN-13 built from this prefix + a
 * counter + a check digit, via the form's "Generate" button.
 *
 * Default `20`: the GS1 range 20–29 is reserved for in-store /
 * restricted-distribution use, so a generated code can never clash with a
 * real manufacturer product. NULL falls back to that default in
 * Company::barcodePrefix(), so existing installs need no backfill.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('company', function (Blueprint $table) {
            $table->string('barcode_prefix', 8)->nullable()->after('sku_format');
        });
    }

    public function down(): void
    {
        Schema::table('company', function (Blueprint $table) {
            $table->dropColumn('barcode_prefix');
        });
    }
};
