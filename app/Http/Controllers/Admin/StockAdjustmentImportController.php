<?php

namespace App\Http\Controllers\Admin;

use App\Actions\Inventory\ImportStockAdjustmentsFromCsv;
use App\Http\Controllers\Controller;
use App\Models\StockAdjustment;
use App\Models\StockAdjustmentReason;
use App\Models\Store;
use App\Services\Excel\SpreadsheetWriter;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** CSV / XLSX wizard that creates a stock-adjustment draft without posting it. */
class StockAdjustmentImportController extends Controller
{
    private const STAGING_DISK = 'local';

    private const STAGING_DIR = 'imports/stock-adjustments';

    public function index(): View
    {
        $this->authorize('create', StockAdjustment::class);

        return view('admin.inventory.adjustments.import.index', [
            'stores' => accessible_stores(),
            'reasons' => StockAdjustmentReason::query()->active()->ordered()->get(['id', 'code', 'name']),
            'defaultStoreId' => active_store_id(),
        ]);
    }

    public function template(Request $request, SpreadsheetWriter $writer): StreamedResponse
    {
        $this->authorize('create', StockAdjustment::class);
        $format = in_array($request->query('format'), ['csv', 'xlsx'], true)
            ? $request->query('format')
            : 'csv';

        return $writer->stream(
            'stock-adjustment-import-template.'.$format,
            [
                __('inventory.adjustments.import.fields.product_sku'),
                __('inventory.adjustments.import.fields.variant_sku'),
                __('inventory.adjustments.import.fields.barcode'),
                __('inventory.adjustments.import.fields.direction'),
                __('inventory.adjustments.import.fields.quantity'),
                __('inventory.adjustments.import.fields.unit_cost'),
                __('inventory.adjustments.import.fields.batch_number'),
                __('inventory.adjustments.import.fields.manufacture_date'),
                __('inventory.adjustments.import.fields.expiry_date'),
                __('inventory.adjustments.import.fields.notes'),
            ],
            fn (callable $write) => $write([
                ['SKU-1001', '', '', 'IN', '25', '12.5000', '', '', '', __('inventory.adjustments.import.sample_opening_note')],
                ['MED-500', 'MED-500-10', '', 'OUT', '2', '', 'LOT-2026-01', '', '', __('inventory.adjustments.import.sample_damaged_note')],
            ]),
        );
    }

    public function preview(Request $request, ImportStockAdjustmentsFromCsv $import): View|RedirectResponse
    {
        $this->authorize('create', StockAdjustment::class);
        $data = $request->validate($this->rules(includeFile: true), $this->messages());
        abort_unless_store_access((int) $data['store_id']);

        $stored = $this->stage($request);
        $absolutePath = Storage::disk(self::STAGING_DISK)->path($stored);

        try {
            $preview = $import->preview($absolutePath);
        } catch (\Throwable $e) {
            Storage::disk(self::STAGING_DISK)->delete($stored);

            return redirect()->route('admin.inventory.adjustments.import')
                ->with('error', __('inventory.adjustments.import.errors.unreadable', ['error' => $e->getMessage()]));
        }

        if (count(array_filter($preview['headers'], fn ($header) => trim((string) $header) !== '')) < 2) {
            Storage::disk(self::STAGING_DISK)->delete($stored);

            return redirect()->route('admin.inventory.adjustments.import')
                ->with('error', __('inventory.adjustments.import.errors.not_a_sheet'));
        }

        return view('admin.inventory.adjustments.import.review', [
            'preview' => $preview,
            'stagedPath' => $stored,
            'targetFields' => ImportStockAdjustmentsFromCsv::TARGET_FIELDS,
            'document' => $this->document($data),
            'documentStore' => Store::query()->findOrFail((int) $data['store_id']),
            'documentReason' => filled($data['reason_code_id'] ?? null)
                ? StockAdjustmentReason::query()->find((int) $data['reason_code_id'])
                : null,
        ]);
    }

    public function commit(Request $request, ImportStockAdjustmentsFromCsv $import): View|RedirectResponse
    {
        $this->authorize('create', StockAdjustment::class);
        $data = $request->validate($this->rules(includeMapping: true), $this->messages());
        abort_unless_store_access((int) $data['store_id']);
        $absolutePath = $this->resolveStagedPath($data['staged_path'], $request->user()?->id);

        try {
            $summary = $import->commit(
                $absolutePath,
                $data['mapping'],
                $this->document($data),
                $request->user(),
            );
        } catch (\Throwable $e) {
            Storage::disk(self::STAGING_DISK)->delete($data['staged_path']);

            return redirect()->route('admin.inventory.adjustments.import')->with('error', $e->getMessage());
        }

        Storage::disk(self::STAGING_DISK)->delete($data['staged_path']);

        return view('admin.inventory.adjustments.import.done', ['summary' => $summary]);
    }

    /** @return array<string, mixed> */
    private function rules(bool $includeFile = false, bool $includeMapping = false): array
    {
        $rules = [
            'store_id' => ['required', 'integer', Rule::exists('stores', 'id')->where('is_active', true)],
            'adjustment_date' => ['required', 'date'],
            'reason_code_id' => ['nullable', 'integer', Rule::exists('stock_adjustment_reasons', 'id')->where('is_active', true)],
            'reason' => ['nullable', 'string', 'max:191'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ];
        if ($includeFile) {
            $rules['file'] = ['required', 'file', 'extensions:csv,xlsx', 'mimes:csv,txt,xlsx', 'max:5120'];
        }
        if ($includeMapping) {
            $rules['staged_path'] = ['required', 'string'];
            $rules['mapping'] = ['required', 'array'];
            $rules['mapping.*'] = ['nullable', 'string', Rule::in(ImportStockAdjustmentsFromCsv::TARGET_FIELDS)];
        }

        return $rules;
    }

    /** @return array<string, string> */
    private function messages(): array
    {
        return [
            'file.required' => __('inventory.adjustments.import.errors.file_required'),
            'file.extensions' => __('inventory.adjustments.import.errors.bad_type'),
            'file.mimes' => __('inventory.adjustments.import.errors.bad_type'),
        ];
    }

    /** @param array<string, mixed> $data @return array<string, mixed> */
    private function document(array $data): array
    {
        return [
            'store_id' => (int) $data['store_id'],
            'adjustment_date' => $data['adjustment_date'],
            'reason_code_id' => filled($data['reason_code_id'] ?? null) ? (int) $data['reason_code_id'] : null,
            'reason' => filled($data['reason'] ?? null) ? trim((string) $data['reason']) : null,
            'notes' => filled($data['notes'] ?? null) ? trim((string) $data['notes']) : null,
        ];
    }

    private function stage(Request $request): string
    {
        $extension = strtolower($request->file('file')->getClientOriginalExtension());

        return $request->file('file')->storeAs(
            self::STAGING_DIR,
            $request->user()?->id.'-'.Str::random(40).'.'.$extension,
            self::STAGING_DISK,
        );
    }

    private function resolveStagedPath(string $stagedPath, ?int $userId): string
    {
        $normalized = str_replace('\\', '/', $stagedPath);
        abort_unless(
            str_starts_with($normalized, self::STAGING_DIR.'/')
            && ! str_contains($normalized, '../')
            && $userId !== null
            && str_starts_with(basename($normalized), $userId.'-'),
            422,
            __('inventory.adjustments.import.errors.invalid_staged_path'),
        );
        abort_unless(Storage::disk(self::STAGING_DISK)->exists($stagedPath), 422, __('inventory.adjustments.import.errors.session_expired'));

        $absolute = Storage::disk(self::STAGING_DISK)->path($stagedPath);
        $realFile = realpath($absolute);
        $realRoot = realpath(Storage::disk(self::STAGING_DISK)->path(self::STAGING_DIR));
        abort_unless(
            $realFile !== false && $realRoot !== false && str_starts_with($realFile, $realRoot.DIRECTORY_SEPARATOR),
            422,
            __('inventory.adjustments.import.errors.invalid_staged_path'),
        );

        return $absolute;
    }
}
