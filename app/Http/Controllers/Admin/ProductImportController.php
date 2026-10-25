<?php

namespace App\Http\Controllers\Admin;

use App\Actions\Products\ImportProductsFromCsv;
use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Services\Excel\SpreadsheetWriter;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Two-step CSV / XLSX → products import wizard.
 *
 *   1. GET  /admin/products/import          — show the upload form
 *   2. POST /admin/products/import/preview  — upload + return header + sample
 *   3. POST /admin/products/import/commit   — apply with confirmed mapping
 *
 * Staging: the upload is moved to `storage/app/imports/products/` and
 * the relative path is round-tripped through hidden form inputs to step
 * 3. Files are cleaned up after a successful commit; the user can also
 * cancel out at any time (the staged file ages out via storage policy).
 */
class ProductImportController extends Controller
{
    /** Where uploads stage while the wizard runs. */
    private const STAGING_DISK = 'local';
    private const STAGING_DIR  = 'imports/products';

    public function index(): View
    {
        $this->authorize('create', Product::class);

        return view('admin.products.import.index');
    }

    /**
     * Stream a ready-to-fill sample template (CSV or XLSX) so the customer
     * knows exactly which columns to provide and what the values look like.
     *
     * Headers use the friendly names the wizard already auto-maps, and the
     * three example rows cover the three v1.0 industries: a retail bottle,
     * a supermarket weighed item, and a pharmacy batch/expiry medicine.
     * Category / Brand / Unit / Tax Group are matched by NAME, so the
     * customer replaces the sample values with names that exist in their
     * own catalog.
     */
    public function template(Request $request, SpreadsheetWriter $writer): StreamedResponse
    {
        $this->authorize('create', Product::class);

        $format = $request->query('format', 'csv');
        if (! in_array($format, ['csv', 'xlsx'], true)) {
            $format = 'csv';
        }

        $headers = [
            'SKU', 'Name', 'Barcode', 'Category', 'Brand', 'Unit', 'Tax Group',
            'Cost Price', 'Selling Price', 'MRP', 'Description', 'Short Description',
            'Track Stock', 'Sold By Weight', 'Track Batches', 'Track Expiry',
            'Reorder Level', 'Reorder Quantity', 'HSN Code', 'Pharmacy Schedule',
            'Generic Name', 'Manufacturer', 'Active', 'Featured', 'Image', 'Image URL',
            // Shelf location at the active store. Blank cells leave any
            // existing location alone — an import can add or correct a
            // location, never erase one.
            'Location Aisle', 'Location Rack', 'Location Shelf', 'Location Bin', 'Location Note',
        ];

        // The three sample rows deliberately show the three ways to declare a
        // photo, because there is no other place to teach it: a filename you
        // will supply yourself, a link the server downloads, or nothing at all.
        $rows = [
            // Retail — `Image` names the photo file the customer will drop on
            // the Bulk images screen later. Nothing is downloaded.
            ['COKE-500', 'Coca-Cola 500ml Bottle', '5449000000996', 'Beverages', 'Coca-Cola', 'Piece', '',
             '30', '50', '55', 'Chilled soft drink, 500ml PET bottle', '500ml soft drink',
             'Yes', 'No', 'No', 'No', '24', '120', '22021010', '', '', '', 'Yes', 'No', 'coke-500.jpg', '',
             'A3', 'R-12', '2', '', 'Chiller end cap'],
            // Supermarket — sold by weight. Photo given as a link instead: the
            // server fetches it from the Bulk images screen's URL tab. Putting
            // the same link in the `Image` column works too — it records both
            // the link and its filename, so either route still finds this row.
            ['APPLE-RED', 'Red Apples (loose)', '', 'Produce', '', 'kg', '',
             '80', '120', '', 'Fresh red apples, sold by weight', '',
             'Yes', 'Yes', 'No', 'No', '5', '20', '', '', '', '', 'Yes', 'No', '',
             'https://cdn.example.com/catalog/red-apples.jpg',
             'A1', '', '', '', 'Produce island'],
            // Pharmacy — batch + expiry tracked. Both image columns left blank;
            // they're optional, and a product can get its photo any time later.
            ['PARA-500', 'Paracetamol 500mg Tablet', '8901234567890', 'Medicines', '', 'Strip', '',
             '10', '18', '20', '', 'Pain & fever relief',
             'Yes', 'No', 'Yes', 'Yes', '10', '50', '30049099', 'H', 'Paracetamol', 'ABC Pharma', 'Yes', 'No', '', '',
             '', 'R-04', '1', 'B-02', 'Behind the counter'],
        ];

        return $writer->stream(
            'product-import-template.'.$format,
            $headers,
            fn (callable $write) => $write($rows),
        );
    }

    public function preview(Request $request, ImportProductsFromCsv $import): View|RedirectResponse
    {
        $this->authorize('create', Product::class);

        // Two rules, deliberately: `extensions` checks the name the customer
        // uploaded (so a .txt / .pdf / .docx is rejected outright), `mimes`
        // checks the content type. text/csv is often reported as text/plain
        // depending on the PHP mime DB, so `txt` stays in the mime list —
        // but only a file actually NAMED .csv or .xlsx gets that far.
        $request->validate([
            'file' => ['required', 'file', 'extensions:csv,xlsx', 'mimes:csv,txt,xlsx', 'max:5120'],
        ], [
            'file.required'   => __('products.import.errors.file_required'),
            'file.extensions' => __('products.import.errors.bad_type'),
            'file.mimes'      => __('products.import.errors.bad_type'),
        ]);

        // Stage under the client extension — it's already been validated
        // to be csv or xlsx, and it's what drives the reader's dispatch.
        $clientExt = strtolower($request->file('file')->getClientOriginalExtension());
        $stored = $request->file('file')->storeAs(
            self::STAGING_DIR,
            // Bind the staged file to the uploader (SEC #69 / #70). The
            // path round-trips through a hidden form field, so anyone who
            // learned or guessed one could commit somebody else file. The
            // owner id is in the NAME, so the commit step can check it
            // without extra state to keep in sync or expire.
            $request->user()?->id.'-'.Str::random(40).'.'.$clientExt,
            self::STAGING_DISK,
        );
        $abs    = Storage::disk(self::STAGING_DISK)->path($stored);

        try {
            $preview = $import->preview($abs);
        } catch (\Throwable $e) {
            Storage::disk(self::STAGING_DISK)->delete($stored);
            return redirect()
                ->route('admin.products.import')
                ->with('error', __('products.import.errors.unreadable', ['error' => $e->getMessage()]));
        }

        // A renamed text file parses "successfully" as a one-column CSV of
        // prose. Nothing usable can be mapped from that, so stop here rather
        // than dropping the customer into a nonsense mapping step.
        $namedColumns = count(array_filter($preview['headers'], fn ($h) => trim((string) $h) !== ''));
        if ($namedColumns < 2) {
            Storage::disk(self::STAGING_DISK)->delete($stored);
            return redirect()
                ->route('admin.products.import')
                ->with('error', __('products.import.errors.not_a_sheet'));
        }

        return view('admin.products.import.review', [
            'preview'      => $preview,
            'stagedPath'   => $stored,
            'targetFields' => ImportProductsFromCsv::TARGET_FIELDS,
        ]);
    }

    public function commit(Request $request, ImportProductsFromCsv $import): RedirectResponse|View
    {
        $this->authorize('create', Product::class);

        $data = $request->validate([
            'staged_path' => ['required', 'string'],
            'mapping'     => ['required', 'array'],
            'mapping.*'   => ['nullable', 'string'],
        ]);

        // The staged path must live inside the designated import folder.
        //
        // A prefix check alone is NOT enough (SEC #16 / defect #33):
        // `imports/products/../../../.env` satisfies `str_starts_with` and
        // still resolves to a real file outside the folder, so a tampered
        // hidden input could have the importer read — and report rows from —
        // the environment file. Reject traversal outright, then confirm the
        // RESOLVED path is genuinely inside the folder.
        if (! str_starts_with($data['staged_path'], self::STAGING_DIR.'/')
            || str_contains(str_replace('\\', '/', $data['staged_path']), '../')) {
            abort(422, 'Invalid staged path.');
        }
        if (! Storage::disk(self::STAGING_DISK)->exists($data['staged_path'])) {
            return redirect()
                ->route('admin.products.import')
                ->with('error', __('products.import.errors.session_expired'));
        }

        $abs = Storage::disk(self::STAGING_DISK)->path($data['staged_path']);

        // Resolve both sides and compare — the authoritative check, immune to
        // whatever encoding got the string past the test above.
        $realFile = realpath($abs);
        $realRoot = realpath(Storage::disk(self::STAGING_DISK)->path(self::STAGING_DIR));

        if ($realFile === false || $realRoot === false
            || ! str_starts_with($realFile, $realRoot.DIRECTORY_SEPARATOR)) {
            abort(422, 'Invalid staged path.');
        }

        // …and it must be OUR staged file (SEC #69). Containment stops the
        // path escaping the folder; it does nothing about one admin committing
        // a file another admin staged. The uploader's id is baked into the
        // name at preview time, so the check needs no extra state.
        abort_unless($this->ownsStagedFile($data['staged_path'], $request->user()?->id), 403);

        try {
            $summary = $import->commit($abs, $data['mapping'], $request->user()?->id);
        } catch (\Throwable $e) {
            return redirect()
                ->route('admin.products.import')
                ->with('error', $e->getMessage());
        }

        // Clean the staged file once we've definitively consumed it.
        Storage::disk(self::STAGING_DISK)->delete($data['staged_path']);

        return view('admin.products.import.done', [
            'summary' => $summary,
            // If the sheet carried image LINKS, the natural next step is the
            // URL tab, not the file drop zone — so the button says so.
            'pendingImageUrls' => Product::query()->pendingImageFetch()->count(),
        ]);
    }

    /**
     * Is this staged file the one THIS user uploaded? Preview names every
     * upload `<userId>-<random>.<ext>`, so ownership is readable from the
     * path with no lookup. Files staged before this convention shipped have
     * no id prefix and are treated as unowned — they can only exist for the
     * few minutes between an upgrade and the next commit.
     */
    private function ownsStagedFile(string $stagedPath, ?int $userId): bool
    {
        if ($userId === null) {
            return false;
        }

        return str_starts_with(basename($stagedPath), $userId.'-');
    }
}
