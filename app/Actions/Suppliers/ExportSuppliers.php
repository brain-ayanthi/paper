<?php

namespace App\Actions\Suppliers;

use App\Actions\Concerns\StreamsListExport;
use App\Events\SuppliersExported;
use App\Models\Supplier;
use App\Services\Excel\SpreadsheetWriter;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Build a downloadable spreadsheet of all suppliers.
 *
 * Extension points:
 *   - filter `suppliers.export.header`  → adjust the header row
 *   - filter `suppliers.export.row`     → adjust each data row (row array + Supplier)
 *   - action `suppliers.before_export`  → side effects before stream
 *   - action `suppliers.after_export`   → fires after rows are buffered
 *   - event  SuppliersExported          → decoupled listeners
 */
class ExportSuppliers
{
    use StreamsListExport;

    public function __construct(private SpreadsheetWriter $writer) {}

    public function __invoke(string $format = 'csv'): StreamedResponse
    {
        $format = strtolower($format);

        $suppliers = Supplier::query()->orderBy('name')->get();

        $header = apply_filters('suppliers.export.header', [
            'ID',
            'Code',
            'Name',
            'Business Name',
            'Contact Person',
            'Email',
            'Phone',
            'Currency',
            'Payment Terms (Days)',
            'City',
            'Country',
            'Outstanding Balance',
            'Active',
            'Created at',
        ]);

        $rows = $suppliers->map(function (Supplier $s) {
            $row = [
                (string) $s->id,
                (string) ($s->code ?? ''),
                (string) $s->name,
                (string) ($s->business_name ?? ''),
                (string) ($s->contact_person ?? ''),
                (string) ($s->email ?? ''),
                (string) ($s->phone ?? ''),
                (string) ($s->default_currency_code ?? ''),
                (string) ($s->payment_terms_days ?? ''),
                (string) ($s->city ?? ''),
                (string) ($s->country_code ?? ''),
                (string) ($s->outstanding_balance ?? '0'),
                $s->is_active ? 'Yes' : 'No',
                optional($s->created_at)->toDateTimeString() ?? '',
            ];
            return apply_filters('suppliers.export.row', $row, $s);
        })->all();

        $count = count($rows);

        return $this->streamListExport($format, 'suppliers', $header, $rows, $count, new SuppliersExported($format, $count));
    }
}
