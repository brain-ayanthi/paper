<?php

namespace App\Actions\Inventory;

use App\Actions\Concerns\StreamsListExport;
use App\Models\StockTake;
use App\Services\Excel\SpreadsheetWriter;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Build a downloadable spreadsheet of all stock takes.
 *
 * Extension points:
 *   - filter `stock_takes.export.header` → adjust the header row
 *   - filter `stock_takes.export.row`    → adjust each data row
 *   - action `stock_takes.before_export` → side effects before stream
 *   - action `stock_takes.after_export`  → fires after rows are buffered
 */
class ExportStockTakes
{
    use StreamsListExport;

    public function __construct(private SpreadsheetWriter $writer) {}

    /**
     * Scoped to one store when `$storeId` is given (the caller clamps it
     * through `enforce_store_access()`). Same class as SEC #85 — this one was
     * not named in that finding but had the identical unscoped `->get()`.
     */
    public function __invoke(string $format = 'csv', ?int $storeId = null): StreamedResponse
    {
        $takes = StockTake::query()
            ->with(['store', 'creator', 'poster'])
            ->withCount('items')
            ->when($storeId, fn ($q) => $q->where('store_id', $storeId))
            ->orderByDesc('take_date')
            ->orderByDesc('id')
            ->get();

        $header = apply_filters('stock_takes.export.header', [
            'ID',
            'Number',
            'Name',
            'Date',
            'Store',
            'Status',
            'Items',
            'Posted at',
            'Posted by',
            'Created by',
            'Created at',
        ]);

        $rows = $takes->map(function (StockTake $t) {
            $row = [
                (string) $t->id,
                (string) $t->number,
                (string) ($t->name ?? ''),
                optional($t->take_date)->toDateString() ?? '',
                (string) ($t->store?->name ?? ''),
                (string) $t->status,
                (string) ($t->items_count ?? 0),
                optional($t->posted_at)->toDateTimeString() ?? '',
                (string) ($t->poster?->name ?? ''),
                (string) ($t->creator?->name ?? ''),
                optional($t->created_at)->toDateTimeString() ?? '',
            ];
            return apply_filters('stock_takes.export.row', $row, $t);
        })->all();

        $count = count($rows);

        return $this->streamListExport(
            $format, 'stock-takes', $header, $rows, $count, null, 'stock_takes',
        );
    }
}
