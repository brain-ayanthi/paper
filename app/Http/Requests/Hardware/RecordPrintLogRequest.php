<?php

namespace App\Http\Requests\Hardware;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validates the print-log POST sent by the client bridge after a print
 * attempt. Server-trusted fields (store, terminal, user) are filled in
 * the action from the session — the client only reports what it did.
 */
class RecordPrintLogRequest extends FormRequest
{
    /**
     * The endpoint used to accept anyone signed in (SEC #37). It writes an
     * audit row attributed to the caller's store/terminal/user, so an
     * unprivileged account could fabricate print history. Require a
     * print-capable permission — receipts and labels are the only two
     * printer types, and the two permissions below are what gate producing
     * each of them in the first place.
     */
    public function authorize(): bool
    {
        $user = $this->user();

        if ($user === null) {
            return false;
        }

        return $this->input('printer_type') === 'label'
            ? $user->hasPermission('products.view') || $user->hasPermission('hardware.test_print')
            : $user->hasPermission('sales.print_receipt') || $user->hasPermission('hardware.test_print');
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'reference_type' => ['required', 'string', Rule::in(['Sale', 'SaleReturn', 'Shift', 'Label'])],
            'reference_id'   => ['nullable', 'integer'],
            'printer_type'   => ['required', Rule::in(['receipt', 'label'])],
            'mode'           => ['required', Rule::in(['webusb', 'browser_print', 'network'])],
            'status'         => ['required', Rule::in(['success', 'failed', 'queued'])],
            'error_message'  => ['nullable', 'string', 'max:1000'],
            'bytes_size'     => ['nullable', 'integer', 'min:0'],
        ];
    }

    /**
     * The reference must be a record the caller can actually reach (SEC #37).
     * Without this, `{reference_type: Sale, reference_id: <anything>}` was
     * taken at face value, so the audit trail could be seeded with rows
     * pointing at another branch's sales.
     *
     * `Label` has no backing record — it's a product-label batch, not one
     * row — so only the three real types are resolved.
     */
    public function after(): array
    {
        return [
            function (\Illuminate\Validation\Validator $validator) {
                $id = $this->input('reference_id');

                if ($id === null || $this->user()?->is_super_admin) {
                    return;
                }

                $table = match ($this->input('reference_type')) {
                    'Sale'       => 'sales',
                    'SaleReturn' => 'sale_returns',
                    'Shift'      => 'shifts',
                    default      => null,
                };

                if ($table === null) {
                    return;
                }

                $storeId = \Illuminate\Support\Facades\DB::table($table)
                    ->where('id', (int) $id)
                    ->value('store_id');

                if ($storeId === null
                    || ! in_array((int) $storeId, (array) $this->user()?->accessibleStoreIds(), true)) {
                    $validator->errors()->add('reference_id', __('validation.exists', ['attribute' => 'reference']));
                }
            },
        ];
    }
}
