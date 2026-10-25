<?php

namespace App\Actions\Products;

use App\Actions\Concerns\StreamsListExport;
use App\Events\ProductsExported;
use App\Models\Product;
use App\Services\Excel\SpreadsheetWriter;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Build a downloadable spreadsheet of every product.
 *
 * Shelf locations are included for the ACTIVE store, pick face only, so
 * the file round-trips through {@see ImportProductsFromCsv}: export →
 * fill the Location columns in a spreadsheet → re-import. That is how a
 * 2,000-SKU catalog gets its locations entered; doing it one product at
 * a time in the editor is not realistic.
 *
 * Eager-loads category / brand / unit so cells render human names not
 * raw ids. Rows stream in lazy() pages of 1000 (each page eager-loaded,
 * written, then released) so memory stays flat even on 10k-SKU catalogs
 * (code-review #22).
 *
 * Extension points:
 *   - filter `products.export.header`  → adjust the header row
 *   - filter `products.export.row`     → adjust each data row (row + Product)
 *   - action `products.before_export`  → side effects before stream
 *   - action `products.after_export`   → fires after rows are buffered
 *   - event  ProductsExported          → decoupled listeners (audit log, stats)
 */
class ExportProducts
{
    use StreamsListExport;

    public function __construct(private SpreadsheetWriter $writer) {}

    public function __invoke(string $format = 'csv'): StreamedResponse
    {
        $format = strtolower($format);

        // Shelf locations round-trip through the importer, so the export
        // has to carry them. Scoped to the ACTIVE store — a rack number at
        // another branch would be nonsense in this file, and re-importing
        // it would write it to the wrong store.
        $storeId = current_store_id();

        $query = Product::query()
            ->with([
                'category:id,name',
                'brand:id,name',
                'unit:id,code,name',
                'taxGroup:id,name',
                'locations' => fn ($q) => $q
                    ->forStore($storeId)
                    ->productLevel()
                    ->inWalkOrder(),
            ])
            ->ordered();

        $header = apply_filters('products.export.header', [
            'ID', 'SKU', 'Barcode', 'Name', 'Category', 'Brand', 'Unit', 'Tax rule',
            'Cost price', 'Selling price', 'MRP',
            'Track stock', 'Sold by weight', 'Track batches', 'Track expiry',
            'HSN', 'Pharmacy schedule', 'Generic name', 'Manufacturer',
            // Only the pick face is exported. A product's godown / fridge /
            // cabinet rows have nowhere to go in a flat sheet — one row per
            // product is what makes the file re-importable. Those extra
            // locations stay in the app, untouched by any import.
            'Location aisle', 'Location rack', 'Location shelf', 'Location bin', 'Location note',
            // Both ways a photo can be declared, so the file round-trips: the
            // filename the customer will drop in, and a usable image link.
            // Prefer the original source link when present; directly uploaded
            // images fall back to their public storage URL.
            'Active', 'Featured', 'Image', 'Image URL', 'Created at', 'Updated at',
        ]);

        $buildRow = function (Product $p) use ($storeId) {
            $loc = $p->primaryLocation($storeId);
            $imageUrl = $p->image_src_url
                ?: ($p->image_url ? url($p->image_url) : '');

            $row = [
                (string) $p->id,
                (string) $p->sku,
                (string) ($p->barcode ?? ''),
                (string) $p->name,
                optional($p->category)->name ?? '',
                optional($p->brand)->name ?? '',
                $p->unit ? "{$p->unit->name} ({$p->unit->code})" : '',
                optional($p->taxGroup)->name ?? '',
                (string) $p->cost_price,
                (string) $p->selling_price,
                $p->mrp !== null ? (string) $p->mrp : '',
                $p->track_stock     ? 'Yes' : 'No',
                $p->sold_by_weight  ? 'Yes' : 'No',
                $p->track_batches   ? 'Yes' : 'No',
                $p->track_expiry    ? 'Yes' : 'No',
                (string) ($p->hsn_code ?? ''),
                (string) ($p->pharmacy_schedule ?? ''),
                (string) ($p->generic_name ?? ''),
                (string) ($p->manufacturer ?? ''),
                (string) ($loc?->aisle ?? ''),
                (string) ($loc?->rack  ?? ''),
                (string) ($loc?->shelf ?? ''),
                (string) ($loc?->bin   ?? ''),
                (string) ($loc?->note  ?? ''),
                $p->is_active   ? 'Yes' : 'No',
                $p->is_featured ? 'Yes' : 'No',
                (string) ($p->image_ref ?? ''),
                (string) $imageUrl,
                optional($p->created_at)->toDateTimeString() ?? '',
                optional($p->updated_at)->toDateTimeString() ?? '',
            ];
            return apply_filters('products.export.row', $row, $p);
        };

        // A cheap COUNT keeps the hook/event payloads identical while the
        // rows themselves stream (code-review #22).
        $count = (int) $query->clone()->count();

        // Stream in pages instead of materializing the whole catalog + a
        // mapped copy in memory (code-review #22): lazy() pulls 1000 models
        // at a time WITH their eager-loads and preserves ordered(), and each
        // page is written and released before the next loads — memory stays
        // flat on a 10k-SKU catalog, which is what OpenSpout was picked for.
        return $this->streamListExport($format, 'products', $header, function (callable $write) use ($query, $buildRow) {
            foreach ($query->lazy(1000)->chunk(1000) as $page) {
                $write($page->map($buildRow)->all());
            }
        }, $count, new ProductsExported($format, $count));
    }
}
