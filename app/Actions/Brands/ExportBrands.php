<?php

namespace App\Actions\Brands;

use App\Actions\Concerns\StreamsListExport;
use App\Events\BrandsExported;
use App\Models\Brand;
use App\Services\Excel\SpreadsheetWriter;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Build a downloadable spreadsheet of every brand.
 *
 * Extension points:
 *   - filter `brands.export.header`  → adjust the header row
 *   - filter `brands.export.row`     → adjust each data row (row array + Brand)
 *   - action `brands.before_export`  → side effects before stream
 *   - action `brands.after_export`   → fires after rows are buffered
 *   - event  BrandsExported          → decoupled listeners
 */
class ExportBrands
{
    use StreamsListExport;

    public function __construct(private SpreadsheetWriter $writer) {}

    public function __invoke(string $format = 'csv'): StreamedResponse
    {
        $format = strtolower($format);

        $brands = Brand::query()->ordered()->get();

        $header = apply_filters('brands.export.header', [
            'ID',
            'Name',
            'Description',
            // The logo FILENAME, so an export → edit → re-import round-trip
            // keeps each brand pointed at its artwork.
            'Logo',
            // …and the link, for brands whose artwork the server downloads.
            // Re-importing the same link does NOT re-queue the download.
            'Logo URL',
            'Active',
            'Created at',
            'Updated at',
        ]);

        $rows = $brands->map(function (Brand $b) {
            $row = [
                (string) $b->id,
                (string) $b->name,
                (string) ($b->description ?? ''),
                // Prefer the declared ref; fall back to the stored file's
                // basename so brands whose logo was uploaded by hand still
                // export something re-importable.
                (string) ($b->logo_ref ?? ($b->logo_path ? basename($b->logo_path) : '')),
                (string) ($b->logo_src_url ?? ''),
                $b->is_active ? 'Yes' : 'No',
                optional($b->created_at)->toDateTimeString() ?? '',
                optional($b->updated_at)->toDateTimeString() ?? '',
            ];
            return apply_filters('brands.export.row', $row, $b);
        })->all();

        $count = count($rows);

        return $this->streamListExport($format, 'brands', $header, $rows, $count, new BrandsExported($format, $count));
    }
}
