<?php

namespace App\Http\Controllers\Admin;

use App\Actions\Categories\ImportCategoriesFromCsv;
use App\Http\Controllers\Controller;
use App\Models\Category;
use App\Services\Excel\SpreadsheetWriter;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Two-step CSV / XLSX → categories import wizard — the sibling of
 * {@see ProductImportController}.
 *
 *   1. GET  /admin/categories/import          — show the upload form
 *   2. POST /admin/categories/import/preview  — upload + return header + sample
 *   3. POST /admin/categories/import/commit   — apply with confirmed mapping
 *
 * Staging: the upload is moved to `storage/app/imports/categories/` and
 * its relative path is round-tripped through a hidden input to step 3.
 */
class CategoryImportController extends Controller
{
    /** Where uploads stage while the wizard runs. */
    private const STAGING_DISK = 'local';
    private const STAGING_DIR  = 'imports/categories';

    public function index(): View
    {
        $this->authorize('create', Category::class);

        return view('admin.categories.import.index');
    }

    /**
     * Stream a ready-to-fill sample template (CSV or XLSX). Headers use the
     * friendly names the wizard auto-maps, mirroring the export columns so a
     * customer can export, tweak, and re-import. Parent + Tax rule are
     * matched by NAME.
     */
    public function template(Request $request, SpreadsheetWriter $writer): StreamedResponse
    {
        $this->authorize('create', Category::class);

        $format = $request->query('format', 'csv');
        if (! in_array($format, ['csv', 'xlsx'], true)) {
            $format = 'csv';
        }

        $headers = ['Name', 'Parent', 'Description', 'Color', 'Tax rule', 'Active'];

        $rows = [
            // A root department…
            ['Beverages', '', 'Drinks — chilled and ambient', '#06B6D4', '', 'Yes'],
            // …and a child that references the root above by name.
            ['Soft Drinks', 'Beverages', 'Carbonated and still soft drinks', '', '', 'Yes'],
            ['Medicines', '', 'Pharmacy items', '#10B981', '', 'Yes'],
        ];

        return $writer->stream(
            'category-import-template.'.$format,
            $headers,
            fn (callable $write) => $write($rows),
        );
    }

    public function preview(Request $request, ImportCategoriesFromCsv $import): View|RedirectResponse
    {
        $this->authorize('create', Category::class);

        // `extensions` guards the uploaded filename (a .txt or .pdf never gets
        // in), `mimes` guards the content type — text/csv is often reported as
        // text/plain, hence `txt` staying in the mime list. Same pair as the
        // products import.
        $request->validate([
            'file' => ['required', 'file', 'extensions:csv,xlsx', 'mimes:csv,txt,xlsx', 'max:5120'],
        ], [
            'file.required'   => __('categories.import.errors.file_required'),
            'file.extensions' => __('categories.import.errors.bad_type'),
            'file.mimes'      => __('categories.import.errors.bad_type'),
        ]);

        // Stage under the (already validated) client extension — it's what
        // drives the reader's CSV-vs-XLSX dispatch.
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
        $abs = Storage::disk(self::STAGING_DISK)->path($stored);

        try {
            $preview = $import->preview($abs);
        } catch (\Throwable $e) {
            Storage::disk(self::STAGING_DISK)->delete($stored);
            return redirect()
                ->route('admin.categories.import')
                ->with('error', __('categories.import.errors.unreadable', ['error' => $e->getMessage()]));
        }

        // A renamed text file parses as a one-column CSV of prose — nothing
        // mappable. Stop here instead of showing a nonsense mapping step.
        $namedColumns = count(array_filter($preview['headers'], fn ($h) => trim((string) $h) !== ''));
        if ($namedColumns < 2) {
            Storage::disk(self::STAGING_DISK)->delete($stored);
            return redirect()
                ->route('admin.categories.import')
                ->with('error', __('categories.import.errors.not_a_sheet'));
        }

        return view('admin.categories.import.review', [
            'preview'      => $preview,
            'stagedPath'   => $stored,
            'targetFields' => ImportCategoriesFromCsv::TARGET_FIELDS,
        ]);
    }

    public function commit(Request $request, ImportCategoriesFromCsv $import): RedirectResponse|View
    {
        $this->authorize('create', Category::class);

        $data = $request->validate([
            'staged_path' => ['required', 'string'],
            'mapping'     => ['required', 'array'],
            'mapping.*'   => ['nullable', 'string'],
        ]);

        // The staged path must live inside our import folder. A prefix check
        // alone is not enough — `imports/categories/../../../.env` satisfies
        // it and still resolves to a real file (SEC #71, the sibling of #16).
        if (! str_starts_with($data['staged_path'], self::STAGING_DIR.'/')
            || str_contains(str_replace('\\', '/', $data['staged_path']), '../')) {
            abort(422, 'Invalid staged path.');
        }
        if (! Storage::disk(self::STAGING_DISK)->exists($data['staged_path'])) {
            return redirect()
                ->route('admin.categories.import')
                ->with('error', __('categories.import.errors.session_expired'));
        }

        $abs = Storage::disk(self::STAGING_DISK)->path($data['staged_path']);

        // Authoritative check: resolve both sides and confirm containment.
        $realFile = realpath($abs);
        $realRoot = realpath(Storage::disk(self::STAGING_DISK)->path(self::STAGING_DIR));

        if ($realFile === false || $realRoot === false
            || ! str_starts_with($realFile, $realRoot.DIRECTORY_SEPARATOR)) {
            abort(422, 'Invalid staged path.');
        }

        // …and it must be OUR staged file (SEC #70) — see the sibling check
        // in ProductImportController.
        abort_unless($this->ownsStagedFile($data['staged_path'], $request->user()?->id), 403);

        try {
            $summary = $import->commit($abs, $data['mapping'], $request->user()?->id);
        } catch (\Throwable $e) {
            return redirect()
                ->route('admin.categories.import')
                ->with('error', $e->getMessage());
        }

        Storage::disk(self::STAGING_DISK)->delete($data['staged_path']);

        return view('admin.categories.import.done', [
            'summary' => $summary,
        ]);
    }

    /** Staged files are named `<userId>-<random>.<ext>` (SEC #70). */
    private function ownsStagedFile(string $stagedPath, ?int $userId): bool
    {
        if ($userId === null) {
            return false;
        }

        return str_starts_with(basename($stagedPath), $userId.'-');
    }
}
