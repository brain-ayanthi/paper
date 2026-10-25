<?php

namespace App\Http\Controllers\Admin;

use App\Actions\Brands\ImportBrandsFromCsv;
use App\Http\Controllers\Controller;
use App\Models\Brand;
use App\Services\Excel\SpreadsheetWriter;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Two-step CSV / XLSX → brands import wizard — the sibling of
 * {@see CategoryImportController}.
 *
 *   1. GET  /admin/brands/import          — show the upload form
 *   2. POST /admin/brands/import/preview  — upload + return header + sample
 *   3. POST /admin/brands/import/commit   — apply with confirmed mapping
 *
 * Staging: the upload is moved to `storage/app/imports/brands/` and its
 * relative path is round-tripped through a hidden input to step 3.
 *
 * Logos are NOT part of this upload — the sheet names files, and the
 * bytes arrive afterwards via {@see BulkBrandLogoController}. Mixing
 * binaries into the wizard would put the whole catalogue behind one
 * `post_max_size` on shared hosting.
 */
class BrandImportController extends Controller
{
    /** Where uploads stage while the wizard runs. */
    private const STAGING_DISK = 'local';
    private const STAGING_DIR  = 'imports/brands';

    public function index(): View
    {
        $this->authorize('create', Brand::class);

        return view('admin.brands.import.index');
    }

    /**
     * Stream a ready-to-fill sample template (CSV or XLSX). Headers use the
     * friendly names the wizard auto-maps, mirroring the export columns so a
     * customer can export, tweak, and re-import.
     */
    public function template(Request $request, SpreadsheetWriter $writer): StreamedResponse
    {
        $this->authorize('create', Brand::class);

        $format = $request->query('format', 'csv');
        if (! in_array($format, ['csv', 'xlsx'], true)) {
            $format = 'csv';
        }

        $headers = ['Name', 'Description', 'Logo', 'Logo URL', 'Active'];

        // The three rows show the three ways to declare artwork: a filename
        // you'll drop in yourself, a link the server downloads, or nothing.
        $rows = [
            ['Acme', 'House brand — everyday staples', 'acme.png', '', 'Yes'],
            ['Contoso', 'Imported confectionery', '', 'https://cdn.example.com/logos/contoso.png', 'Yes'],
            // Both columns optional: a brand with no artwork imports fine.
            ['Fabrikam', 'Cleaning supplies', '', '', 'Yes'],
        ];

        return $writer->stream(
            'brand-import-template.'.$format,
            $headers,
            fn (callable $write) => $write($rows),
        );
    }

    public function preview(Request $request, ImportBrandsFromCsv $import): View|RedirectResponse
    {
        $this->authorize('create', Brand::class);

        // `extensions` guards the uploaded filename (a .txt or .pdf never gets
        // in), `mimes` guards the content type — text/csv is often reported as
        // text/plain, hence `txt` staying in the mime list. Same pair as the
        // products + categories imports.
        $request->validate([
            'file' => ['required', 'file', 'extensions:csv,xlsx', 'mimes:csv,txt,xlsx', 'max:5120'],
        ], [
            'file.required'   => __('brands.import.errors.file_required'),
            'file.extensions' => __('brands.import.errors.bad_type'),
            'file.mimes'      => __('brands.import.errors.bad_type'),
        ]);

        // Stage under the (already validated) client extension — it's what
        // drives the reader's CSV-vs-XLSX dispatch.
        $clientExt = strtolower($request->file('file')->getClientOriginalExtension());
        $stored = $request->file('file')->storeAs(
            self::STAGING_DIR,
            Str::random(40).'.'.$clientExt,
            self::STAGING_DISK,
        );
        $abs = Storage::disk(self::STAGING_DISK)->path($stored);

        try {
            $preview = $import->preview($abs);
        } catch (\Throwable $e) {
            Storage::disk(self::STAGING_DISK)->delete($stored);
            return redirect()
                ->route('admin.brands.import')
                ->with('error', __('brands.import.errors.unreadable', ['error' => $e->getMessage()]));
        }

        // A renamed text file parses as a one-column CSV of prose — nothing
        // mappable. Stop here instead of showing a nonsense mapping step.
        $namedColumns = count(array_filter($preview['headers'], fn ($h) => trim((string) $h) !== ''));
        if ($namedColumns < 2) {
            Storage::disk(self::STAGING_DISK)->delete($stored);
            return redirect()
                ->route('admin.brands.import')
                ->with('error', __('brands.import.errors.not_a_sheet'));
        }

        return view('admin.brands.import.review', [
            'preview'      => $preview,
            'stagedPath'   => $stored,
            'targetFields' => ImportBrandsFromCsv::TARGET_FIELDS,
        ]);
    }

    public function commit(Request $request, ImportBrandsFromCsv $import): RedirectResponse|View
    {
        $this->authorize('create', Brand::class);

        $data = $request->validate([
            'staged_path' => ['required', 'string'],
            'mapping'     => ['required', 'array'],
            'mapping.*'   => ['nullable', 'string'],
        ]);

        // Defence-in-depth: the staged path must live inside our import folder.
        if (! str_starts_with($data['staged_path'], self::STAGING_DIR.'/')) {
            abort(422, 'Invalid staged path.');
        }
        if (! Storage::disk(self::STAGING_DISK)->exists($data['staged_path'])) {
            return redirect()
                ->route('admin.brands.import')
                ->with('error', __('brands.import.errors.session_expired'));
        }

        $abs = Storage::disk(self::STAGING_DISK)->path($data['staged_path']);

        try {
            $summary = $import->commit($abs, $data['mapping'], $request->user()?->id);
        } catch (\Throwable $e) {
            return redirect()
                ->route('admin.brands.import')
                ->with('error', $e->getMessage());
        }

        Storage::disk(self::STAGING_DISK)->delete($data['staged_path']);

        return view('admin.brands.import.done', [
            'summary' => $summary,
            // Drives which tab the "upload the logos" button lands on.
            'pendingLogoUrls' => Brand::query()->pendingLogoFetch()->count(),
        ]);
    }
}
