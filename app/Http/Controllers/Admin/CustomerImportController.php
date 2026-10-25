<?php

namespace App\Http\Controllers\Admin;

use App\Actions\Customers\ImportCustomersFromCsv;
use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Services\Excel\SpreadsheetWriter;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** CSV / XLSX customer import wizard, mirroring CategoryImportController. */
class CustomerImportController extends Controller
{
    private const STAGING_DISK = 'local';

    private const STAGING_DIR = 'imports/customers';

    public function index(): View
    {
        $this->authorize('import', Customer::class);

        return view('admin.customers.import.index');
    }

    public function template(Request $request, SpreadsheetWriter $writer): StreamedResponse
    {
        $this->authorize('import', Customer::class);

        $format = $request->query('format', 'csv');
        if (! in_array($format, ['csv', 'xlsx'], true)) {
            $format = 'csv';
        }

        $headers = [
            'Code', 'Name', 'Phone', 'WhatsApp phone', 'WhatsApp opt out',
            'Email', 'Date of birth', 'Gender', 'Business', 'Business name',
            'GSTIN', 'PAN', 'Tax registration number', 'Customer group',
            'Default discount %', 'Credit limit', 'Notes',
            'Address label', 'Address line 1', 'Address line 2', 'Address city',
            'Address state', 'Address postal code', 'Address country code',
            'Address landmark', 'Default address', 'Active',
        ];

        $rows = [
            [
                'C-1001', 'Asha Kumar', '+91 98765 43210', '', 'No',
                'asha@example.com', '1992-06-14', 'Female', 'No', '', '', '', '',
                'Regular', '', '5000', 'Prefers WhatsApp',
                'Home', '12 Lake Road', '', 'Bengaluru', 'Karnataka', '560001',
                'IN', 'Near the park', 'Yes', 'Yes',
            ],
            [
                '', 'Northwind Traders', '080 5555 0100', '', 'Yes',
                'accounts@northwind.example', '', '', 'Yes', 'Northwind Traders',
                '22AAAAA0000A1Z5', 'AAAAA0000A', '', 'Wholesale', '5', '25000', '',
                'Office', '45 Market Street', 'Suite 2', 'Mumbai', 'Maharashtra',
                '400001', 'IN', '', 'Yes', 'Yes',
            ],
        ];

        return $writer->stream(
            'customer-import-template.'.$format,
            $headers,
            fn (callable $write) => $write($rows),
        );
    }

    public function preview(Request $request, ImportCustomersFromCsv $import): View|RedirectResponse
    {
        $this->authorize('import', Customer::class);

        $request->validate([
            'file' => ['required', 'file', 'extensions:csv,xlsx', 'mimes:csv,txt,xlsx', 'max:5120'],
        ], [
            'file.required' => __('customers.import.errors.file_required'),
            'file.extensions' => __('customers.import.errors.bad_type'),
            'file.mimes' => __('customers.import.errors.bad_type'),
        ]);

        $extension = strtolower($request->file('file')->getClientOriginalExtension());
        $stored = $request->file('file')->storeAs(
            self::STAGING_DIR,
            $request->user()?->id.'-'.Str::random(40).'.'.$extension,
            self::STAGING_DISK,
        );
        $absolutePath = Storage::disk(self::STAGING_DISK)->path($stored);

        try {
            $preview = $import->preview($absolutePath);
        } catch (\Throwable $e) {
            Storage::disk(self::STAGING_DISK)->delete($stored);

            return redirect()
                ->route('admin.customers.import')
                ->with('error', __('customers.import.errors.unreadable', ['error' => $e->getMessage()]));
        }

        $namedColumns = count(array_filter($preview['headers'], fn ($header) => trim((string) $header) !== ''));
        if ($namedColumns < 2) {
            Storage::disk(self::STAGING_DISK)->delete($stored);

            return redirect()
                ->route('admin.customers.import')
                ->with('error', __('customers.import.errors.not_a_sheet'));
        }

        return view('admin.customers.import.review', [
            'preview' => $preview,
            'stagedPath' => $stored,
            'targetFields' => ImportCustomersFromCsv::TARGET_FIELDS,
        ]);
    }

    public function commit(Request $request, ImportCustomersFromCsv $import): View|RedirectResponse
    {
        $this->authorize('import', Customer::class);

        $data = $request->validate([
            'staged_path' => ['required', 'string'],
            'mapping' => ['required', 'array'],
            'mapping.*' => ['nullable', 'string'],
        ]);

        $normalizedPath = str_replace('\\', '/', $data['staged_path']);
        if (! str_starts_with($normalizedPath, self::STAGING_DIR.'/') || str_contains($normalizedPath, '../')) {
            abort(422, __('customers.import.errors.invalid_staged_path'));
        }
        if (! Storage::disk(self::STAGING_DISK)->exists($data['staged_path'])) {
            return redirect()
                ->route('admin.customers.import')
                ->with('error', __('customers.import.errors.session_expired'));
        }

        $absolutePath = Storage::disk(self::STAGING_DISK)->path($data['staged_path']);
        $realFile = realpath($absolutePath);
        $realRoot = realpath(Storage::disk(self::STAGING_DISK)->path(self::STAGING_DIR));

        if ($realFile === false || $realRoot === false || ! str_starts_with($realFile, $realRoot.DIRECTORY_SEPARATOR)) {
            abort(422, __('customers.import.errors.invalid_staged_path'));
        }

        abort_unless($this->ownsStagedFile($data['staged_path'], $request->user()?->id), 403);

        try {
            $summary = $import->commit($absolutePath, $data['mapping'], $request->user()?->id);
        } catch (\Throwable $e) {
            return redirect()
                ->route('admin.customers.import')
                ->with('error', $e->getMessage());
        }

        Storage::disk(self::STAGING_DISK)->delete($data['staged_path']);

        return view('admin.customers.import.done', ['summary' => $summary]);
    }

    private function ownsStagedFile(string $stagedPath, ?int $userId): bool
    {
        return $userId !== null && str_starts_with(basename($stagedPath), $userId.'-');
    }
}
