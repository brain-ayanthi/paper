<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('company', function (Blueprint $table) {
            $table->string('pricing_method', 24)
                ->default('product_based')
                ->after('auto_apply_markup_on_receive');
            $table->timestamp('batch_pricing_initialized_at')
                ->nullable()
                ->after('pricing_method');
        });

        Schema::table('purchase_items', function (Blueprint $table) {
            $table->decimal('batch_selling_price', 15, 4)
                ->nullable()
                ->after('unit_cost');
        });
        Schema::table('stock_adjustment_items', function (Blueprint $table) {
            $table->decimal('batch_selling_price', 15, 4)->nullable()->after('unit_cost');
        });
    }

    public function down(): void
    {
        Schema::table('stock_adjustment_items', function (Blueprint $table) {
            $table->dropColumn('batch_selling_price');
        });
        Schema::table('purchase_items', function (Blueprint $table) {
            $table->dropColumn('batch_selling_price');
        });

        Schema::table('company', function (Blueprint $table) {
            $table->dropColumn(['pricing_method', 'batch_pricing_initialized_at']);
        });
    }
};
