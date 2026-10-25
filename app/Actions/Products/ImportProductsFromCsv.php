<?php

namespace App\Actions\Products;

use App\Actions\Concerns\FreesSoftDeletedUnique;
use App\Models\Brand;
use App\Models\Category;
use App\Models\Product;
use App\Models\TaxGroup;
use App\Models\Unit;
use App\Services\Excel\SpreadsheetReader;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * Streaming CSV / XLSX → products importer.
 *
 * Two modes share one parsing engine:
 *
 *   - `preview(path)`  → reads headers + the first N data rows, returns
 *     a structure the wizard UI uses to render the column-mapping step.
 *     Side-effect free (nothing touches the products table).
 *
 *   - `commit(path, mapping)` → re-reads the whole file using the user's
 *     confirmed column-to-field mapping, validates each row, and creates
 *     or updates products. Returns a summary (created / updated / errors).
 *
 * Identity: rows match on SKU. A row whose SKU matches an existing
 * product becomes an update; a row whose SKU is new becomes a create.
 * Empty / blank SKUs are skipped with an error.
 *
 * Foreign-key columns (`category`, `brand`, `unit`, `tax_group`) are
 * matched by NAME (case-insensitive). A blank value leaves the column
 * null on the product; an unknown name is reported as a row error.
 *
 * Shelf locations (`location_aisle` … `location_note`) are the one
 * non-product-column group handled here. They write the product's PICK
 * FACE at the active store via {@see UpsertPrimaryProductLocation}, and
 * they are **additive only**: a blank cell leaves the existing location
 * alone. A customer re-importing a price list whose Location columns
 * happen to be empty must not lose the shelf data they typed in — so
 * "blank = erase" is deliberately not available through import. Clearing
 * is done in the editor, or with the list's bulk Clear action.
 *
 * v1 scope: top-level product fields + the pick-face location. Variants /
 * kits / per-store prices are not importable yet — they need a different
 * mapping shape and a separate wizard step. Nor are a product's SECONDARY
 * locations (godown, fridge, cabinet): a flat sheet has one row per
 * product, so those stay in the app and no import ever touches them.
 *
 * Hook points:
 *   - filter `products.import.row`    → adjust a normalized payload pre-validation
 *   - action `products.before_import` → fires before the loop opens
 *   - action `products.after_import`  → fires after the loop closes with the summary
 */
class ImportProductsFromCsv
{
    use FreesSoftDeletedUnique;

    /** Importable target fields. Order drives the wizard's
     *  "map column to..." dropdown. */
    public const TARGET_FIELDS = [
        'sku', 'name', 'barcode',
        'category', 'brand', 'unit', 'tax_group',
        'cost_price', 'selling_price', 'mrp',
        'description', 'short_description',
        'track_stock', 'sold_by_weight', 'track_batches', 'track_expiry',
        'reorder_level', 'reorder_quantity',
        'hsn_code', 'pharmacy_schedule', 'generic_name', 'manufacturer',
        'is_active', 'is_featured',
        // Declared image FILENAME (e.g. "apple-front.jpg") — recorded in
        // products.image_ref so the Bulk images screen can match an uploaded
        // file to this product. Not the image itself. A cell holding a URL is
        // recorded BOTH ways: see the image block in normalizeRow().
        'image',
        // Explicit image URL column, for sheets that carry a filename AND a
        // link. Recorded in products.image_src_url for the server to fetch.
        'image_url',
        // Shelf location at the ACTIVE store — the product's pick face.
        // Blank cells LEAVE THE EXISTING LOCATION ALONE (see applyLocation);
        // an import can add or correct a location, never erase one.
        'location_aisle', 'location_rack', 'location_shelf', 'location_bin', 'location_note',
    ];

    /** Location columns, in the order they appear in the export. */
    private const LOCATION_FIELDS = [
        'location_aisle' => 'aisle',
        'location_rack'  => 'rack',
        'location_shelf' => 'shelf',
        'location_bin'   => 'bin',
        'location_note'  => 'note',
    ];

    /** Maximum data rows surfaced in the preview step. The wizard
     *  shows them as a small table for visual confirmation. */
    public const PREVIEW_ROWS = 10;

    public function __construct(
        private SpreadsheetReader $reader,
        private UpsertPrimaryProductLocation $upsertLocation,
    ) {}

    /**
     * Side-effect-free preview of an uploaded spreadsheet.
     *
     * Returns:
     *   [
     *     'headers'         => [<col 0 string>, ...],
     *     'suggested_map'   => [<header> => <target field or null>, ...],
     *     'rows'            => [[col0, col1, ...], ...],  // first PREVIEW_ROWS data rows
     *     'total_columns'   => int,
     *   ]
     *
     * @return array{headers: array<int, string>, suggested_map: array<string, ?string>, rows: array<int, array<int, string>>, total_columns: int}
     */
    public function preview(string $path): array
    {
        $headers = [];
        $sample  = [];
        foreach ($this->reader->rows($path, self::PREVIEW_ROWS + 1) as $i => $row) {
            if ($i === 0) {
                $headers = $row;
                continue;
            }
            $sample[] = $row;
        }

        $suggested = [];
        foreach ($headers as $h) {
            $suggested[$h] = $this->suggestField($h);
        }

        return [
            'headers'        => $headers,
            'suggested_map'  => $suggested,
            'rows'           => $sample,
            'total_columns'  => count($headers),
        ];
    }

    /**
     * Apply the import.
     *
     * `$mapping` is column-index → target-field. Indexes that map to
     * null are ignored. The wizard sends the indexes as numeric strings
     * via the form, so we normalize before use.
     *
     * `$storeId` is the store any mapped Location columns are written to.
     * Defaults to the active store; pass it explicitly from a job or a
     * test where there's no session.
     *
     * @param array<int|string, ?string> $mapping
     * @return array{created: int, updated: int, skipped: int, errors: array<int, array{row: int, message: string}>}
     */
    public function commit(string $path, array $mapping, ?int $userId = null, ?int $storeId = null): array
    {
        do_action('products.before_import', $path, $mapping);

        $mapping = $this->normalizeMapping($mapping);
        if (! in_array('sku', $mapping, true)) {
            throw new \RuntimeException('SKU column must be mapped — every product row needs a SKU to identify it.');
        }

        $lookups = $this->loadLookups();

        // Which store mapped Location columns land in, and whether any were
        // mapped at all. Resolved once — not per row.
        $storeId       = $storeId ?? current_store_id() ?? default_store_id();
        $locationCols  = array_intersect(array_keys(self::LOCATION_FIELDS), $mapping);
        $writeLocation = $locationCols !== [] && $storeId !== null;

        $created = 0;
        $updated = 0;
        $skipped = 0;
        $errors  = [];

        // 1-based for human-friendly error reporting (row 1 = header,
        // row 2 = first data row).
        $rowNum = 0;

        foreach ($this->reader->rows($path) as $i => $cells) {
            $rowNum++;
            if ($i === 0) continue; // header

            $errorCountBefore = count($errors);
            $payload = $this->buildPayload($cells, $mapping, $lookups, $errors, $rowNum);
            if ($payload === null) {
                $skipped++;
                continue;
            }
            // Lookup miss (unknown category / brand / unit / tax) is
            // treated as a hard skip — the user mapped the column on
            // purpose; silently creating the row with a null FK would
            // mask the data-quality issue.
            if (count($errors) > $errorCountBefore) {
                $skipped++;
                continue;
            }

            $payload = apply_filters('products.import.row', $payload, $cells, $mapping);

            $existing = Product::query()->where('sku', $payload['sku'])->first();

            // Location values live in product_locations rather than on the
            // product payload, so validate them separately before entering
            // the transaction. UpsertPrimaryProductLocation defensively
            // caps values to the database width, but an import must report
            // an over-length cell instead of silently saving a truncated
            // coordinate or note.
            $locationValues = $writeLocation
                ? $this->locationValues($cells, $mapping)
                : [];

            $locationValidator = $this->validateLocationValues($locationValues);
            if ($locationValidator->fails()) {
                $errors[] = [
                    'row'     => $rowNum,
                    'message' => $locationValidator->errors()->first(),
                ];
                $skipped++;
                continue;
            }

            // A sheet that came out of Export carries every product's existing
            // image URL back in unchanged, and a normal workflow is export →
            // edit prices → re-import. That must not re-queue the whole
            // catalogue for download: only a CHANGED link is a new job, so an
            // identical one keeps whatever fetch state it already had.
            $currentImageUrl = $existing?->image_url
                ? url($existing->image_url)
                : null;

            if ($existing
                && $existing->image_src_url === null
                && $currentImageUrl !== null
                && ($payload['image_src_url'] ?? null) === $currentImageUrl
            ) {
                // Export fills the URL column for directly uploaded images
                // with their public storage URL. Importing the same sheet back
                // into this product must not queue a download from itself.
                unset(
                    $payload['image_src_url'],
                    $payload['image_fetch_status'],
                    $payload['image_fetch_error'],
                );
            } elseif ($existing
                && array_key_exists('image_src_url', $payload)
                && $payload['image_src_url'] !== null
                && $payload['image_src_url'] === $existing->image_src_url
            ) {
                $payload['image_fetch_status'] = $existing->image_fetch_status;
                $payload['image_fetch_error']  = $existing->image_fetch_error;
            }

            $validator = $this->validateRow($payload, $existing);
            if ($validator->fails()) {
                $errors[] = [
                    'row'     => $rowNum,
                    'message' => $validator->errors()->first(),
                ];
                $skipped++;
                continue;
            }

            try {
                DB::transaction(function () use (&$created, &$updated, $existing, $payload, $userId, $storeId, $writeLocation, $locationValues) {
                    // Free a SKU/barcode still held by a trashed product so
                    // the insert/rename doesn't hit the all-rows unique index
                    // (mirrors CreateProduct / UpdateProduct).
                    $this->reclaimSoftDeletedIdentifiers(
                        $payload['sku'] ?? null,
                        $payload['barcode'] ?? null,
                        $existing?->id,
                    );

                    if ($existing) {
                        $payload['updated_by'] = $userId;
                        $existing->fill($payload)->save();
                        $product = $existing;
                        $updated++;
                    } else {
                        $payload['slug']       = Str::slug($payload['name']).'-'.Str::lower(Str::random(5));
                        $payload['created_by'] = $userId;
                        $payload['updated_by'] = $userId;
                        $product = Product::create($payload);
                        $created++;
                    }

                    // Shelf location, if the user mapped any Location column.
                    // Blank cells are a no-op inside the action, so a sheet
                    // that only fills a location for SOME rows leaves the
                    // others exactly as they were.
                    if ($writeLocation) {
                        ($this->upsertLocation)($product, (int) $storeId, $locationValues);
                    }
                });
            } catch (QueryException) {
                // Last-resort net — any DB constraint we didn't validate
                // for (race condition, an index we don't know about) becomes
                // a skippable row error instead of aborting the whole import.
                $errors[] = [
                    'row'     => $rowNum,
                    'message' => "Row {$rowNum} could not be saved (database constraint). Check for duplicate values.",
                ];
                $skipped++;
                continue;
            }
        }

        $summary = [
            'created' => $created,
            'updated' => $updated,
            'skipped' => $skipped,
            'errors'  => $errors,
        ];

        do_action('products.after_import', $summary);

        return $summary;
    }

    /* ── Internals ─────────────────────────────────────────────── */

    /**
     * Best-guess mapping for a header string. Used only to seed the UI
     * — the user can override any suggestion before committing.
     */
    private function suggestField(string $header): ?string
    {
        $needle = $this->canonical($header);

        $aliases = [
            'sku'              => ['sku', 'code', 'productsku', 'item', 'itemcode'],
            'name'             => ['name', 'productname', 'title', 'displayname'],
            'barcode'          => ['barcode', 'ean', 'upc', 'gtin'],
            'category'         => ['category', 'cat', 'department'],
            'brand'            => ['brand', 'make', 'manufacturerbrand'],
            'unit'             => ['unit', 'uom', 'soldby'],
            'tax_group'        => ['tax', 'taxrule', 'taxgroup', 'vat'],
            'cost_price'       => ['cost', 'costprice', 'buyprice', 'purchaseprice'],
            'selling_price'    => ['price', 'sellingprice', 'sellprice', 'retailprice', 'msrp'],
            'mrp'              => ['mrp', 'maxretailprice', 'maxprice'],
            'description'      => ['description', 'desc', 'longdescription', 'notes'],
            'short_description'=> ['shortdescription', 'shortdesc', 'summary'],
            'track_stock'      => ['trackstock', 'inventoryon', 'stocktracked'],
            'sold_by_weight'   => ['soldbyweight', 'weighed', 'byweight'],
            'track_batches'    => ['trackbatches', 'batchtracked'],
            'track_expiry'     => ['trackexpiry', 'expirytracked'],
            'reorder_level'    => ['reorderlevel', 'minstock', 'lowstockthreshold'],
            'reorder_quantity' => ['reorderquantity', 'reorderqty'],
            'hsn_code'         => ['hsn', 'hsncode', 'taxcode'],
            'pharmacy_schedule'=> ['schedule', 'drugschedule', 'pharmacyschedule'],
            'generic_name'     => ['generic', 'genericname', 'molecule'],
            'manufacturer'     => ['manufacturer', 'maker', 'pharma'],
            'is_active'        => ['active', 'enabled', 'available'],
            'is_featured'      => ['featured', 'highlight'],
            'image'            => ['image', 'imagefile', 'imagename', 'photo', 'picture', 'img'],
            'image_url'        => ['imageurl', 'imagelink', 'imagesrc', 'imgurl', 'photourl', 'pictureurl'],
            // Bare "aisle" / "rack" are what a shop's own spreadsheet
            // actually calls these, so match those as well as the
            // "location …" headers our export writes.
            'location_aisle'   => ['locationaisle', 'aisle', 'aislenumber', 'aisleno'],
            'location_rack'    => ['locationrack', 'rack', 'rackno', 'racknumber', 'location', 'shelflocation', 'position'],
            'location_shelf'   => ['locationshelf', 'shelf', 'shelfno', 'shelfnumber'],
            'location_bin'     => ['locationbin', 'bin', 'binno', 'binlocation'],
            'location_note'    => ['locationnote', 'locationnotes'],
        ];

        foreach ($aliases as $field => $candidates) {
            if (in_array($needle, $candidates, true)) {
                return $field;
            }
        }

        return null;
    }

    /** Strip non-alphanumerics + lowercase. "Selling Price" ⇒ "sellingprice". */
    private function canonical(string $s): string
    {
        return preg_replace('/[^a-z0-9]+/', '', strtolower($s)) ?: '';
    }

    /**
     * Coerce the wizard's mapping (column index keys may be strings, or
     * the UI may send keys like "1"/"2") into a plain int-keyed array
     * of target fields, with null entries (= "skip this column") dropped.
     *
     * @param  array<int|string, ?string> $mapping
     * @return array<int, string>
     */
    private function normalizeMapping(array $mapping): array
    {
        $out = [];
        foreach ($mapping as $idx => $target) {
            if ($target === null || $target === '') continue;
            if (! in_array($target, self::TARGET_FIELDS, true)) continue;
            $out[(int) $idx] = $target;
        }
        return $out;
    }

    /**
     * Cache lookup tables for category / brand / unit / tax — building
     * them once per import lets us match by name without N queries per
     * row.
     *
     * @return array{categories: array<string,int>, brands: array<string,int>, units: array<string,int>, taxes: array<string,int>}
     */
    private function loadLookups(): array
    {
        return [
            'categories' => Category::query()->pluck('id', 'name')
                ->mapWithKeys(fn ($id, $name) => [strtolower((string) $name) => (int) $id])
                ->all(),
            'brands'     => Brand::query()->pluck('id', 'name')
                ->mapWithKeys(fn ($id, $name) => [strtolower((string) $name) => (int) $id])
                ->all(),
            // Units are matched by code first, then name — a column like
            // "kg" should resolve even if the unit's display name is
            // "Kilogram".
            'units_code' => Unit::query()->pluck('id', 'code')
                ->mapWithKeys(fn ($id, $code) => [strtolower((string) $code) => (int) $id])
                ->all(),
            'units_name' => Unit::query()->pluck('id', 'name')
                ->mapWithKeys(fn ($id, $name) => [strtolower((string) $name) => (int) $id])
                ->all(),
            'taxes'      => TaxGroup::query()->pluck('id', 'name')
                ->mapWithKeys(fn ($id, $name) => [strtolower((string) $name) => (int) $id])
                ->all(),
        ];
    }

    /**
     * Translate a raw spreadsheet row into a product attributes array.
     * Records lookup misses + missing-required errors in $errors and
     * returns null when the row can't be salvaged.
     *
     * @param  array<int, string>                                                                                                          $cells
     * @param  array<int, string>                                                                                                          $mapping
     * @param  array{categories: array<string,int>, brands: array<string,int>, units_code: array<string,int>, units_name: array<string,int>, taxes: array<string,int>} $lookups
     * @param  array<int, array{row:int, message:string}>                                                                                  $errors
     */
    private function buildPayload(array $cells, array $mapping, array $lookups, array &$errors, int $rowNum): ?array
    {
        $get = fn (string $field) => $this->valueFor($cells, $mapping, $field);

        $sku = trim((string) $get('sku'));
        if ($sku === '') {
            // Blank-SKU rows are silently skipped — trailing blank rows
            // are very common in hand-edited spreadsheets.
            return null;
        }

        $payload = ['sku' => $sku];

        // ONLY MAPPED COLUMNS GO INTO THE PAYLOAD.
        //
        // The payload is `fill()`ed onto existing products, so any key
        // present here overwrites that column. Building every key
        // unconditionally meant a narrow sheet — "SKU, Rack" to set shelf
        // locations, say — silently blanked description, HSN, generic name
        // and brand, and reset every flag to its default, on every product
        // it touched. Nothing in the wizard warned about it and nothing in
        // the summary reported it.
        //
        // Absent keys also let create() fall through to the DB defaults,
        // which are the same values the old defaults hard-coded here.
        // (`image` already worked this way — see below; this extends the
        // same rule to the rest of the columns.)
        $strings = [
            'name', 'barcode', 'description', 'short_description',
            'hsn_code', 'pharmacy_schedule', 'generic_name', 'manufacturer',
        ];
        foreach ($strings as $field) {
            if (in_array($field, $mapping, true)) {
                // `name` keeps its raw value — it's required, and blanking
                // it must fail validation rather than quietly become null.
                $payload[$field] = $field === 'name'
                    ? $get($field)
                    : $this->nullableString($get($field));
            }
        }

        $numbers = ['cost_price', 'selling_price', 'mrp', 'reorder_level', 'reorder_quantity'];
        foreach ($numbers as $field) {
            if (in_array($field, $mapping, true)) {
                $payload[$field] = $this->numeric($get($field));
            }
        }

        $flags = [
            'track_stock'    => true,
            'sold_by_weight' => false,
            'track_batches'  => false,
            'track_expiry'   => false,
            'is_active'      => true,
            'is_featured'    => false,
        ];
        foreach ($flags as $field => $default) {
            if (in_array($field, $mapping, true)) {
                $payload[$field] = $this->boolish($get($field), $default);
            }
        }

        // Declared image. There are two independent ways a photo can reach a
        // product, and a row may use either or both:
        //
        //   image_ref      a FILENAME the customer supplies later by dropping a
        //                  folder on the Bulk images screen.
        //   image_src_url  a URL the SERVER downloads for them.
        //
        // A cell holding a URL fills BOTH — the link to fetch, and its basename
        // so the very same sheet still works for a customer who would rather
        // download the pictures themselves and drop the folder in. Neither is a
        // mode and neither disables the other; whichever the customer runs last
        // is the image that sticks.
        //
        // Basename-only on the ref side, so a stray path ("images/apple.jpg")
        // still matches the file "apple.jpg". Only written when the column is
        // actually mapped, so a later prices-only re-import (no image column)
        // doesn't wipe pending refs or URLs on existing products.
        if (in_array('image', $mapping, true)) {
            $raw = $get('image');
            $payload['image_ref'] = $this->imageRef($raw);
            $this->applySourceUrl($payload, $this->imageSourceUrl($raw));
        }

        // A separate `Image URL` column, for sheets that carry a filename and a
        // link side by side. Applied after `image` so it wins the URL half
        // while leaving that column's filename alone.
        if (in_array('image_url', $mapping, true)) {
            $this->applySourceUrl($payload, $this->imageSourceUrl($get('image_url')));
        }

        // FK lookups by name — same "only when mapped" rule.
        $foreignKeys = [
            'category'  => ['category_id',  'categories'],
            'brand'     => ['brand_id',     'brands'],
            'tax_group' => ['tax_group_id', 'taxes'],
        ];
        foreach ($foreignKeys as $column => [$attribute, $lookupKey]) {
            if (in_array($column, $mapping, true)) {
                $payload[$attribute] = $this->resolveLookup(
                    $get($column), $lookups[$lookupKey], $column, $errors, $rowNum,
                );
            }
        }

        // Unit — try code then name.
        if (in_array('unit', $mapping, true)) {
            $unitRaw = trim((string) $get('unit'));
            $payload['unit_id'] = null;

            if ($unitRaw !== '') {
                $key = strtolower($unitRaw);
                $payload['unit_id'] = $lookups['units_code'][$key]
                    ?? $lookups['units_name'][$key]
                    ?? null;
                if ($payload['unit_id'] === null) {
                    $errors[] = ['row' => $rowNum, 'message' => "Unknown unit '{$unitRaw}'."];
                }
            }
        }

        return $payload;
    }

    /**
     * Validate a row's normalized payload using the same shape as the
     * editor's Form Request (minus the variant / kit / image rules).
     */
    private function validateRow(array &$payload, ?Product $existing): \Illuminate\Contracts\Validation\Validator
    {
        $rules = [
            'sku'           => ['required', 'string', 'max:64'],
            'name'          => ['required', 'string', 'max:191'],
            // Barcode is unique across products. Ignore the row's own
            // product (SKU-matched update) and skip soft-deleted rows —
            // a deleted product's barcode is reclaimable, and the trait
            // below tombstones it before the write. Without this, a clash
            // raised a raw 1062 mid-import instead of a skippable error.
            'barcode'       => [
                'nullable', 'string', 'max:64',
                Rule::unique('products', 'barcode')
                    ->ignore($existing?->id)
                    ->whereNull('deleted_at'),
            ],
            // Category is required, matching the editor's Form Request —
            // every product belongs to a department.
            'category_id'   => ['required', 'integer'],
            'unit_id'       => ['required', 'integer'],
            'cost_price'    => ['required', 'numeric', 'gt:0', 'lte:selling_price', 'max:9999999999.9999'],
            'selling_price' => ['required', 'numeric', 'gt:0', 'max:9999999999.9999'],
            'mrp'           => ['nullable', 'numeric', 'gt:0', 'max:9999999999.9999', 'gte:selling_price'],
        ];

        // On existing rows, allow the row to omit columns the user
        // didn't include in the mapping — fall back to the current
        // product values so we don't fail validation just because the
        // CSV is narrower than the products table.
        //
        // `$payload` is by reference so the back-fill also reaches the
        // save in commit(). Without that, a mapped-but-blank required
        // column passed validation here and then wrote NULL to a NOT NULL
        // column, surfacing as an opaque "database constraint" row error.
        if ($existing) {
            foreach (['name', 'category_id', 'unit_id', 'cost_price', 'selling_price'] as $col) {
                // `array_key_exists`, not isset/direct read: unmapped columns
                // are now absent from the payload entirely, and reading them
                // raised "Undefined array key" here.
                if (! array_key_exists($col, $payload) || $payload[$col] === null || $payload[$col] === '') {
                    $payload[$col] = $existing->{$col};
                }
            }
        }

        // Friendlier attribute names in the row-error summary.
        return Validator::make($payload, $rules, [], [
            'category_id' => 'category',
            'unit_id'     => 'unit',
        ]);
    }

    /**
     * Validate the mapped pick-face values using the same limits as the
     * interactive product editor.
     *
     * @param  array<string, string>  $values
     */
    private function validateLocationValues(array $values): \Illuminate\Contracts\Validation\Validator
    {
        return Validator::make($values, [
            'aisle' => ['nullable', 'string', 'max:16'],
            'rack'  => ['nullable', 'string', 'max:16'],
            'shelf' => ['nullable', 'string', 'max:16'],
            'bin'   => ['nullable', 'string', 'max:16'],
            'note'  => ['nullable', 'string', 'max:64'],
        ], [], [
            'aisle' => 'location aisle',
            'rack'  => 'location rack',
            'shelf' => 'location shelf',
            'bin'   => 'location bin',
            'note'  => 'location note',
        ]);
    }

    /**
     * The row's shelf-location values, keyed the way
     * {@see UpsertPrimaryProductLocation} expects (aisle / rack / shelf /
     * bin / note).
     *
     * Unmapped columns and blank cells simply don't appear in the result,
     * and the action leaves anything absent untouched. That is what keeps
     * a narrow re-import — a price update with no Location columns, or a
     * sheet where only half the rows have a rack — from erasing shelf data
     * the shop already entered.
     *
     * @param  array<int, string>  $cells
     * @param  array<int, string>  $mapping
     * @return array<string, string>
     */
    private function locationValues(array $cells, array $mapping): array
    {
        $out = [];

        foreach (self::LOCATION_FIELDS as $column => $key) {
            $value = $this->nullableString($this->valueFor($cells, $mapping, $column));
            if ($value !== null) {
                $out[$key] = $value;
            }
        }

        return $out;
    }

    private function valueFor(array $cells, array $mapping, string $field): ?string
    {
        $index = array_search($field, $mapping, true);
        if ($index === false) return null;
        return $cells[$index] ?? null;
    }

    private function nullableString(?string $v): ?string
    {
        $v = $v === null ? null : trim($v);
        return ($v === null || $v === '') ? null : $v;
    }

    /**
     * Normalize a declared image reference to a bare filename. The Bulk images
     * screen matches on the file's own name, so a cell holding a path
     * ("images/apple.jpg") or a URL ("https://…/apple.jpg") both reduce to
     * "apple.jpg". A blank cell leaves image_ref null.
     */
    private function imageRef(?string $v): ?string
    {
        $v = $this->nullableString($v);
        if ($v === null) {
            return null;
        }

        // Strip any query string a URL might carry, then take the basename.
        $v = preg_replace('/[?#].*$/', '', $v) ?? $v;
        $base = basename(str_replace('\\', '/', $v));

        return $base === '' ? null : mb_substr($base, 0, 255);
    }

    /**
     * The fetchable URL in a cell, or null when the cell holds a plain
     * filename.
     *
     * Only http(s) counts. "apple.jpg", "images/apple.jpg" and "ftp://…" are
     * all filenames as far as this is concerned and live in image_ref alone.
     *
     * The URL is stored exactly as written — every safety decision about it
     * (HTTPS, private addresses, redirects, size, file type) belongs to
     * RemoteImageFetcher at fetch time, not to the importer. Judging it here
     * would only mean a link that happens to be down on import day never gets
     * recorded, and so never gets retried.
     */
    private function imageSourceUrl(?string $v): ?string
    {
        $v = $this->nullableString($v);

        if ($v === null || preg_match('~^https?://~i', $v) !== 1) {
            return null;
        }

        // Longer than the fetcher will ever accept — record nothing rather
        // than a link guaranteed to be refused later.
        return mb_strlen($v) > 2048 ? null : $v;
    }

    /**
     * Put a fetchable URL on the payload, resetting the fetch state with it.
     *
     * The reset matters: the URL screen skips rows marked `failed` so a dead
     * link isn't retried on every run, which means a customer who fixes a bad
     * link and re-imports would otherwise see their correction silently
     * ignored. A new URL is always a new job.
     *
     * @param array<string, mixed> $payload
     */
    private function applySourceUrl(array &$payload, ?string $url): void
    {
        $payload['image_src_url']      = $url;
        $payload['image_fetch_status'] = $url === null ? null : 'pending';
        $payload['image_fetch_error']  = null;
    }

    private function numeric(?string $v): ?string
    {
        $v = $v === null ? null : trim($v);
        if ($v === null || $v === '') return null;
        // Strip thousands separators ("1,234.50" → "1234.50"). We can't
        // safely strip *all* commas in locales that use comma as the
        // decimal separator — but our v1 audience is English-locale and
        // OpenSpout already normalizes XLSX numbers.
        $v = str_replace(',', '', $v);
        return is_numeric($v) ? $v : null;
    }

    private function boolish(?string $v, bool $default): bool
    {
        if ($v === null) return $default;
        $v = strtolower(trim($v));
        if ($v === '') return $default;
        return in_array($v, ['1', 'true', 'yes', 'y', 'on'], true);
    }

    private function resolveLookup(?string $raw, array $byName, string $field, array &$errors, int $rowNum): ?int
    {
        $raw = $raw === null ? '' : trim($raw);
        if ($raw === '') return null;
        $key = strtolower($raw);
        if (! isset($byName[$key])) {
            $errors[] = ['row' => $rowNum, 'message' => "Unknown {$field} '{$raw}'."];
            return null;
        }
        return $byName[$key];
    }

    /**
     * Free a SKU / barcode still held by a soft-deleted product so a fresh
     * insert (or rename) doesn't collide with the all-rows unique index.
     * Delegates to the ONE shared reclaim (code-review #39) — the former
     * inline copy's "self-contained file" rationale didn't hold: the app
     * deploys as a whole and the trait autoloads with it.
     */
    private function reclaimSoftDeletedIdentifiers(?string $sku, ?string $barcode, ?int $exceptId = null): void
    {
        $this->freeSoftDeletedUnique(
            Product::class,
            ['sku' => $sku, 'barcode' => $barcode],
            ['sku', 'barcode'],
            $exceptId,
            prefixMax: 64,
        );
    }
}
