<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('purchase_items', function (Blueprint $table) {
            $table->decimal('batch_mrp', 15, 4)->nullable()->after('batch_selling_price');
        });

        Schema::table('stock_adjustment_items', function (Blueprint $table) {
            $table->decimal('batch_mrp', 15, 4)->nullable()->after('batch_selling_price');
        });
    }

    public function down(): void
    {
        Schema::table('stock_adjustment_items', function (Blueprint $table) {
            $table->dropColumn('batch_mrp');
        });

        Schema::table('purchase_items', function (Blueprint $table) {
            $table->dropColumn('batch_mrp');
        });
    }
};
