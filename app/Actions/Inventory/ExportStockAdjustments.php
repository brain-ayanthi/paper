<?php

namespace App\Actions\Inventory;

use App\Actions\Concerns\StreamsListExport;
use App\Events\StockAdjustmentsExported;
use App\Models\StockAdjustment;
use App\Models\StockAdjustmentItem;
use App\Services\Excel\SpreadsheetWriter;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Build a downloadable spreadsheet of stock adjustments, scoped to one store
 * when `$storeId` is given (the caller clamps it through
 * `enforce_store_access()`). Unscoped it leaked every branch's adjustments to
 * anyone who could open the list — SEC #85.
 *
 * Extension points:
 *   - filter `stock_adjustments.export.header`  → adjust the header row
 *   - filter `stock_adjustments.export.row`     → adjust each data row
 *   - action `stock_adjustments.before_export`  → side effects before stream
 *   - action `stock_adjustments.after_export`   → fires after rows are buffered
 *   - event  StockAdjustmentsExported           → decoupled listeners
 */
class ExportStockAdjustments
{
    use StreamsListExport;

    public function __construct(private SpreadsheetWriter $writer) {}

    public function __invoke(string $format = 'csv', ?int $storeId = null): StreamedResponse
    {
        $format = strtolower($format);

        $adjustments = StockAdjustment::query()
            ->with(['store', 'reasonCode', 'creator', 'items.product', 'items.variant'])
            ->when($storeId, fn ($q) => $q->where('store_id', $storeId))
            ->orderBy('adjustment_date', 'desc')
            ->get();

        $header = apply_filters('stock_adjustments.export.header', [
            'ID',
            'Number',
            'Date',
            'Store',
            'Product Name',
            'SKU ID',
            'Reason',
            'Notes',
            'Status',
            'Posted at',
            'Created by',
            'Created at',
        ]);

        $rows = $adjustments->flatMap(function (StockAdjustment $a) {
            // Product and SKU belong to the line item, not the adjustment
            // header. Export one row per line so multi-product adjustments
            // remain useful for sorting and filtering in a spreadsheet. Keep
            // header-only records visible as a row with blank product fields.
            $items = $a->items->isEmpty() ? collect([null]) : $a->items;

            return $items->map(function (?StockAdjustmentItem $item) use ($a) {
                $row = [
                    (string) $a->id,
                    (string) $a->number,
                    optional($a->adjustment_date)->toDateString() ?? '',
                    (string) ($a->store?->name ?? ''),
                    (string) ($item?->product?->name ?? ''),
                    (string) ($item?->variant?->sku ?? $item?->product?->sku ?? ''),
                    (string) ($a->reasonCode?->name ?? $a->reason ?? ''),
                    (string) ($a->notes ?? ''),
                    (string) $a->status,
                    optional($a->posted_at)->toDateTimeString() ?? '',
                    (string) ($a->creator?->name ?? ''),
                    optional($a->created_at)->toDateTimeString() ?? '',
                ];

                return apply_filters('stock_adjustments.export.row', $row, $a, $item);
            });
        })->all();

        $count = count($rows);

        return $this->streamListExport(
            $format, 'stock-adjustments', $header, $rows, $count,
            new StockAdjustmentsExported($format, $count), 'stock_adjustments',
        );
    }
}
