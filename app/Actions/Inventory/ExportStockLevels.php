<?php

namespace App\Actions\Inventory;

use App\Actions\Concerns\StreamsListExport;
use App\Events\StockLevelsExported;
use App\Models\Company;
use App\Models\ProductBatch;
use App\Models\StockLevel;
use App\Services\Excel\SpreadsheetWriter;
use App\Support\Inventory\StockLevelBatchDataset;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Build a downloadable spreadsheet of stock levels, scoped to one store when
 * `$storeId` is given (the caller clamps it through `enforce_store_access()`).
 *
 * The scoping is the fix for SEC #85: the on-screen list was store-scoped but
 * this ran an unscoped `->get()`, so a user restricted to one branch could
 * download every branch's inventory from the Export button.
 *
 * Extension points:
 *   - filter `stock_levels.export.header`  → adjust the header row
 *   - filter `stock_levels.export.row`     → adjust each data row
 *   - action `stock_levels.before_export`  → side effects before stream
 *   - action `stock_levels.after_export`   → fires after rows are buffered
 *   - event  StockLevelsExported           → decoupled listeners
 */
class ExportStockLevels
{
    use StreamsListExport;

    public function __construct(
        private SpreadsheetWriter $writer,
        private StockLevelBatchDataset $batchDataset,
    ) {}

    public function __invoke(string $format = 'csv', ?int $storeId = null, string $q = ''): StreamedResponse
    {
        $format = strtolower($format);

        $levels = StockLevel::query()
            ->with([
                'product', 'variant', 'store',
                // Each row resolves its OWN store's pick face out of this,
                // since an unscoped export spans stores.
                'product.locations' => fn ($q) => $q->productLevel()->inWalkOrder(),
            ])
            ->when($storeId, fn ($q) => $q->where('store_id', $storeId))
            ->when($q !== '', function ($query) use ($q) {
                $query->whereHas('product', fn ($product) => $product
                    ->where('name', 'like', "%{$q}%")
                    ->orWhere('sku', 'like', "%{$q}%")
                    ->orWhere('barcode', 'like', "%{$q}%"));
            })
            ->orderBy('store_id')
            ->orderBy('product_id')
            ->get();

        $batchPricing = Company::current()?->usesBatchPricing() ?? false;
        $batches = $batchPricing ? $this->batchDataset->load($levels) : collect();

        $header = apply_filters('stock_levels.export.header', [
            __('inventory.levels.export.columns.id'),
            __('inventory.levels.export.columns.store'),
            __('inventory.levels.export.columns.product'),
            __('inventory.levels.export.columns.sku'),
            __('inventory.levels.export.columns.variant'),
            __('inventory.levels.export.columns.location'),
            ...($batchPricing ? [
                __('inventory.levels.export.columns.batch_lot'),
                __('inventory.levels.export.columns.manufacture_date'),
                __('inventory.levels.export.columns.expiry_date'),
            ] : []),
            __('inventory.levels.export.columns.on_hand'),
            __('inventory.levels.export.columns.reserved'),
            $batchPricing
                ? __('inventory.levels.export.columns.cost_wac')
                : __('inventory.levels.export.columns.average_cost_wac'),
            ...($batchPricing ? [
                __('inventory.levels.export.columns.batch_selling_price'),
                __('inventory.levels.export.columns.batch_mrp'),
            ] : []),
            __('inventory.levels.export.columns.reorder_level'),
            __('inventory.levels.export.columns.last_movement'),
        ]);

        $rows = $levels->flatMap(function (StockLevel $level) use ($batchPricing, $batches) {
            $entries = collect([['batch' => null, 'unallocated' => false, 'quantity' => (string) $level->quantity]]);
            if ($batchPricing && $level->product?->track_batches) {
                $levelBatches = $this->batchDataset->batchesFor($level, $batches);
                $entries = $levelBatches->map(fn (ProductBatch $batch) => [
                    'batch' => $batch,
                    'unallocated' => false,
                    'quantity' => (string) $batch->quantity,
                ]);
                $unallocated = $this->batchDataset->unallocated($level, $levelBatches);
                if (bccomp($unallocated, '0', 4) !== 0 || $entries->isEmpty()) {
                    $entries->push([
                        'batch' => null,
                        'unallocated' => true,
                        'quantity' => $unallocated,
                    ]);
                }
            }

            return $entries->map(function (array $entry) use ($level, $batchPricing) {
                /** @var ProductBatch|null $batch */
                $batch = $entry['batch'];
                $unallocated = $entry['unallocated'];
                $row = [
                    (string) $level->id,
                    (string) ($level->store?->name ?? ''),
                    (string) ($level->product?->name ?? ''),
                    (string) ($level->variant?->sku ?? $level->product?->sku ?? ''),
                    (string) ($level->variant?->label ?? ''),
                    (string) ($level->product?->primaryLocation($level->store_id)?->label() ?? ''),
                    ...($batchPricing ? [
                        $unallocated ? __('inventory.levels.batches.unallocated_label') : (string) ($batch?->batch_number ?? ''),
                        optional($batch?->manufacture_date)->toDateString() ?? '',
                        optional($batch?->expiry_date)->toDateString() ?? '',
                    ] : []),
                    $entry['quantity'],
                    (string) ($batch ? '' : ($level->reserved_quantity ?? '0')),
                    (string) ($batch?->cost_price ?? $level->weighted_average_cost ?? ''),
                    ...($batchPricing ? [
                        (string) ($batch?->selling_price ?? ''),
                        (string) ($batch?->mrp ?? ''),
                    ] : []),
                    (string) ($level->reorder_level_override ?? ''),
                    optional($level->last_movement_at)->toDateTimeString() ?? '',
                ];

                return apply_filters('stock_levels.export.row', $row, $level);
            });
        })->all();

        $count = count($rows);

        return $this->streamListExport(
            $format, 'stock-levels', $header, $rows, $count,
            new StockLevelsExported($format, $count), 'stock_levels',
        );
    }

}
