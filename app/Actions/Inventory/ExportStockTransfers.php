<?php

namespace App\Actions\Inventory;

use App\Actions\Concerns\StreamsListExport;
use App\Events\StockTransfersExported;
use App\Models\StockTransfer;
use App\Services\Excel\SpreadsheetWriter;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Build a downloadable spreadsheet of all stock transfers.
 *
 * Extension points:
 *   - filter `stock_transfers.export.header`  → adjust the header row
 *   - filter `stock_transfers.export.row`     → adjust each data row
 *   - action `stock_transfers.before_export`  → side effects before stream
 *   - action `stock_transfers.after_export`   → fires after rows are buffered
 *   - event  StockTransfersExported           → decoupled listeners
 */
class ExportStockTransfers
{
    use StreamsListExport;

    public function __construct(private SpreadsheetWriter $writer) {}

    /**
     * @param  list<int>|null  $storeIds  Stores the caller may see. Null means
     *                                    unrestricted (super admin).
     */
    public function __invoke(string $format = 'csv', ?array $storeIds = null): StreamedResponse
    {
        $format = strtolower($format);

        $transfers = StockTransfer::query()
            ->with(['fromStore', 'toStore', 'creator'])
            // A transfer spans two stores, so it is visible from either end —
            // the same rule the list query uses (see the controller's
            // filteredBase), not a single active-store filter.
            ->when($storeIds !== null, fn ($q) => $q->where(function ($w) use ($storeIds) {
                $w->whereIn('from_store_id', $storeIds)->orWhereIn('to_store_id', $storeIds);
            }))
            ->orderBy('transfer_date', 'desc')
            ->get();

        $header = apply_filters('stock_transfers.export.header', [
            'ID',
            'Number',
            'Transfer Date',
            'Expected Arrival',
            'From Store',
            'To Store',
            'Status',
            'Notes',
            'Created by',
            'Created at',
        ]);

        $rows = $transfers->map(function (StockTransfer $t) {
            $row = [
                (string) $t->id,
                (string) $t->number,
                optional($t->transfer_date)->toDateString() ?? '',
                optional($t->expected_arrival_date)->toDateString() ?? '',
                (string) ($t->fromStore?->name ?? ''),
                (string) ($t->toStore?->name ?? ''),
                (string) $t->status,
                (string) ($t->notes ?? ''),
                (string) ($t->creator?->name ?? ''),
                optional($t->created_at)->toDateTimeString() ?? '',
            ];
            return apply_filters('stock_transfers.export.row', $row, $t);
        })->all();

        $count = count($rows);

        return $this->streamListExport(
            $format, 'stock-transfers', $header, $rows, $count,
            new StockTransfersExported($format, $count), 'stock_transfers',
        );
    }
}
