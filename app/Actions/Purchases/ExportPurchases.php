<?php

namespace App\Actions\Purchases;

use App\Actions\Concerns\StreamsListExport;
use App\Events\PurchasesExported;
use App\Models\Purchase;
use App\Services\Excel\SpreadsheetWriter;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Build a downloadable spreadsheet of purchases, scoped to one store when
 * `$storeId` is given (the caller clamps it through `enforce_store_access()`).
 *
 * Extension points:
 *   - filter `purchases.export.header`  → adjust the header row
 *   - filter `purchases.export.row`     → adjust each data row (row array + Purchase)
 *   - action `purchases.before_export`  → side effects before stream
 *   - action `purchases.after_export`   → fires after rows are buffered
 *   - event  PurchasesExported          → decoupled listeners
 */
class ExportPurchases
{
    use StreamsListExport;

    public function __construct(private SpreadsheetWriter $writer) {}

    public function __invoke(string $format = 'csv', ?int $storeId = null): StreamedResponse
    {
        $format = strtolower($format);

        $purchases = Purchase::query()
            ->with(['supplier', 'store'])
            ->when($storeId, fn ($q) => $q->where('store_id', $storeId))
            ->orderByDesc('purchase_date')
            ->orderByDesc('id')
            ->get();

        $header = apply_filters('purchases.export.header', [
            'ID',
            'Number',
            'Purchase Date',
            'Due Date',
            'Supplier',
            'Supplier Invoice No',
            'Store',
            'Status',
            'Subtotal',
            'Tax',
            'Discount',
            'Additional Charges',
            'Grand Total',
            'Paid',
            'Balance Due',
            'Currency',
            'Created at',
        ]);

        $rows = $purchases->map(function (Purchase $p) {
            $row = [
                (string) $p->id,
                (string) $p->number,
                optional($p->purchase_date)->toDateString() ?? '',
                optional($p->due_date)->toDateString() ?? '',
                (string) ($p->supplier?->name ?? ''),
                (string) ($p->supplier_invoice_number ?? ''),
                (string) ($p->store?->name ?? ''),
                (string) $p->status,
                (string) ($p->subtotal ?? '0'),
                (string) ($p->tax_total ?? '0'),
                (string) ($p->discount_total ?? '0'),
                (string) ($p->additional_charges_total ?? '0'),
                (string) ($p->grand_total ?? '0'),
                (string) ($p->paid_total ?? '0'),
                (string) ($p->balance_due ?? '0'),
                (string) ($p->currency_code ?? ''),
                optional($p->created_at)->toDateTimeString() ?? '',
            ];
            return apply_filters('purchases.export.row', $row, $p);
        })->all();

        $count = count($rows);

        return $this->streamListExport($format, 'purchases', $header, $rows, $count, new PurchasesExported($format, $count));
    }
}
