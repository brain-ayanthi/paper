<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('stock_transfer_items', function (Blueprint $table) {
            $table->string('batch_number_snapshot', 64)->nullable()->after('unit_cost');
            $table->date('batch_manufacture_date_snapshot')->nullable()->after('batch_number_snapshot');
            $table->date('batch_expiry_date_snapshot')->nullable()->after('batch_manufacture_date_snapshot');
            $table->decimal('batch_cost_price_snapshot', 15, 4)->nullable()->after('batch_expiry_date_snapshot');
            $table->decimal('batch_selling_price_snapshot', 15, 4)->nullable()->after('batch_cost_price_snapshot');
            $table->decimal('batch_mrp_snapshot', 15, 4)->nullable()->after('batch_selling_price_snapshot');
        });

        // Existing in-transit rows predate dispatch snapshots. Freeze the best
        // available source facts at upgrade time so they remain receivable.
        DB::table('stock_transfer_items')
            ->whereNotNull('batch_id')
            ->orderBy('id')
            ->chunkById(500, function ($items) {
                $batches = DB::table('product_batches')
                    ->whereIn('id', $items->pluck('batch_id')->filter()->unique())
                    ->get()
                    ->keyBy('id');

                foreach ($items as $item) {
                    $batch = $batches->get($item->batch_id);
                    if (! $batch) {
                        continue;
                    }

                    DB::table('stock_transfer_items')->where('id', $item->id)->update([
                        'batch_number_snapshot' => $batch->batch_number,
                        'batch_manufacture_date_snapshot' => $batch->manufacture_date,
                        'batch_expiry_date_snapshot' => $batch->expiry_date,
                        'batch_cost_price_snapshot' => $batch->cost_price,
                        'batch_selling_price_snapshot' => $batch->selling_price,
                        'batch_mrp_snapshot' => $batch->mrp,
                    ]);
                }
            });
    }

    public function down(): void
    {
        Schema::table('stock_transfer_items', function (Blueprint $table) {
            $table->dropColumn([
                'batch_number_snapshot',
                'batch_manufacture_date_snapshot',
                'batch_expiry_date_snapshot',
                'batch_cost_price_snapshot',
                'batch_selling_price_snapshot',
                'batch_mrp_snapshot',
            ]);
        });
    }
};
