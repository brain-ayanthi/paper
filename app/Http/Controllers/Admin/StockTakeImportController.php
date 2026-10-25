<?php

namespace App\Http\Controllers\Admin;

use App\Actions\Inventory\ImportStockTakeCountsFromCsv;
use App\Http\Controllers\Controller;
use App\Models\StockTake;
use App\Models\Store;
use App\Services\Excel\SpreadsheetWriter;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** CSV / XLSX wizard that creates a counted stock-take draft. */
class StockTakeImportController extends Controller
{
    private const STAGING_DISK = 'local';

    private const STAGING_DIR = 'imports/stock-takes';

    public function index(): View
    {
        $this->authorize('create', StockTake::class);

        return view('admin.inventory.stock-takes.import.index', [
            'stores' => accessible_stores(),
            'defaultStoreId' => active_store_id(),
        ]);
    }

    public function template(Request $request, SpreadsheetWriter $writer): StreamedResponse
    {
        $this->authorize('create', StockTake::class);
        $format = in_array($request->query('format'), ['csv', 'xlsx'], true)
            ? $request->query('format')
            : 'csv';

        return $writer->stream(
            'stock-take-import-template.'.$format,
            [
                __('stock_takes.import.fields.product_sku'),
                __('stock_takes.import.fields.variant_sku'),
                __('stock_takes.import.fields.barcode'),
                __('stock_takes.import.fields.counted_quantity'),
                __('stock_takes.import.fields.notes'),
            ],
            fn (callable $write) => $write([
                ['SKU-1001', '', '', '24', __('stock_takes.import.sample_front_note')],
                ['TSHIRT', 'TSHIRT-BLUE-M', '', '8', __('stock_takes.import.sample_back_note')],
            ]),
        );
    }

    public function preview(Request $request, ImportStockTakeCountsFromCsv $import): View|RedirectResponse
    {
        $this->authorize('create', StockTake::class);
        $data = $request->validate($this->rules(includeFile: true), $this->messages());
        abort_unless_store_access((int) $data['store_id']);
        $stored = $this->stage($request);
        $absolutePath = Storage::disk(self::STAGING_DISK)->path($stored);

        try {
            $preview = $import->preview($absolutePath);
        } catch (\Throwable $e) {
            Storage::disk(self::STAGING_DISK)->delete($stored);

            return redirect()->route('admin.inventory.stock-takes.import')
                ->with('error', __('stock_takes.import.errors.unreadable', ['error' => $e->getMessage()]));
        }

        if (count(array_filter($preview['headers'], fn ($header) => trim((string) $header) !== '')) < 2) {
            Storage::disk(self::STAGING_DISK)->delete($stored);

            return redirect()->route('admin.inventory.stock-takes.import')
                ->with('error', __('stock_takes.import.errors.not_a_sheet'));
        }

        return view('admin.inventory.stock-takes.import.review', [
            'preview' => $preview,
            'stagedPath' => $stored,
            'targetFields' => ImportStockTakeCountsFromCsv::TARGET_FIELDS,
            'document' => $this->document($data),
            'documentStore' => Store::query()->findOrFail((int) $data['store_id']),
        ]);
    }

    public function commit(Request $request, ImportStockTakeCountsFromCsv $import): View|RedirectResponse
    {
        $this->authorize('create', StockTake::class);
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

            return redirect()->route('admin.inventory.stock-takes.import')->with('error', $e->getMessage());
        }

        Storage::disk(self::STAGING_DISK)->delete($data['staged_path']);

        return view('admin.inventory.stock-takes.import.done', ['summary' => $summary]);
    }

    /** @return array<string, mixed> */
    private function rules(bool $includeFile = false, bool $includeMapping = false): array
    {
        $rules = [
            'store_id' => ['required', 'integer', Rule::exists('stores', 'id')->where('is_active', true)],
            'take_date' => ['required', 'date', 'before_or_equal:today'],
            'name' => ['nullable', 'string', 'max:191'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ];
        if ($includeFile) {
            $rules['file'] = ['required', 'file', 'extensions:csv,xlsx', 'mimes:csv,txt,xlsx', 'max:5120'];
        }
        if ($includeMapping) {
            $rules['staged_path'] = ['required', 'string'];
            $rules['mapping'] = ['required', 'array'];
            $rules['mapping.*'] = ['nullable', 'string', Rule::in(ImportStockTakeCountsFromCsv::TARGET_FIELDS)];
        }

        return $rules;
    }

    /** @return array<string, string> */
    private function messages(): array
    {
        return [
            'file.required' => __('stock_takes.import.errors.file_required'),
            'file.extensions' => __('stock_takes.import.errors.bad_type'),
            'file.mimes' => __('stock_takes.import.errors.bad_type'),
            'take_date.before_or_equal' => __('stock_takes.errors.date_future'),
        ];
    }

    /** @param array<string, mixed> $data @return array<string, mixed> */
    private function document(array $data): array
    {
        return [
            'store_id' => (int) $data['store_id'],
            'take_date' => $data['take_date'],
            'name' => filled($data['name'] ?? null) ? trim((string) $data['name']) : null,
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
            __('stock_takes.import.errors.invalid_staged_path'),
        );
        abort_unless(Storage::disk(self::STAGING_DISK)->exists($stagedPath), 422, __('stock_takes.import.errors.session_expired'));

        $absolute = Storage::disk(self::STAGING_DISK)->path($stagedPath);
        $realFile = realpath($absolute);
        $realRoot = realpath(Storage::disk(self::STAGING_DISK)->path(self::STAGING_DIR));
        abort_unless(
            $realFile !== false && $realRoot !== false && str_starts_with($realFile, $realRoot.DIRECTORY_SEPARATOR),
            422,
            __('stock_takes.import.errors.invalid_staged_path'),
        );

        return $absolute;
    }
}
