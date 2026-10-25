<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Validates PATCH /admin/settings/numbering. Each format string MUST
 * contain a `{seq}` or `{seq:N}` placeholder — without it the generator
 * would produce duplicate numbers and trip the unique constraint on
 * `(store_id, number)`.
 */
class NumberFormatRequest extends FormRequest
{
    public const MAX_LEN = 191;

    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'sale_number_format' => ['nullable', 'string', 'max:'.self::MAX_LEN, 'regex:/\{seq(?::\d+)?\}/'],
            'hold_number_format' => ['nullable', 'string', 'max:'.self::MAX_LEN, 'regex:/\{seq(?::\d+)?\}/'],
            'quotation_number_format' => ['nullable', 'string', 'max:'.self::MAX_LEN, 'regex:/\{seq(?::\d+)?\}/'],
            'refund_number_format' => ['nullable', 'string', 'max:'.self::MAX_LEN, 'regex:/\{seq(?::\d+)?\}/'],
            // SKUs live in a 64-char column, so the template is capped
            // shorter than the receipt-number ones.
            'sku_format'         => ['nullable', 'string', 'max:48', 'regex:/\{seq(?::\d+)?\}/'],
            // In-store barcode prefix: digits only, 1–7 long so the counter
            // + check digit still fit inside an EAN-13.
            'barcode_prefix'     => ['nullable', 'string', 'regex:/^[0-9]{1,7}$/'],
        ];
    }

    public function messages(): array
    {
        return [
            'sale_number_format.regex' => 'Sale number format must include {seq} or {seq:N}.',
            'hold_number_format.regex' => 'Hold number format must include {seq} or {seq:N}.',
            'quotation_number_format.regex' => __('quotations.validation.number_format'),
            'refund_number_format.regex' => __('settings.numbering.validation.refund_format'),
            'sku_format.regex'         => 'SKU format must include {seq} or {seq:N}.',
            'barcode_prefix.regex'     => 'Barcode prefix must be 1–7 digits.',
        ];
    }

    /** @return array<string, mixed> */
    public function numberFormatData(): array
    {
        return [
            'sale_number_format' => trim((string) $this->input('sale_number_format')) ?: null,
            'hold_number_format' => trim((string) $this->input('hold_number_format')) ?: null,
            'quotation_number_format' => trim((string) $this->input('quotation_number_format')) ?: null,
            'refund_number_format' => trim((string) $this->input('refund_number_format')) ?: null,
            'sku_format'         => trim((string) $this->input('sku_format')) ?: null,
            'barcode_prefix'     => trim((string) $this->input('barcode_prefix')) ?: null,
        ];
    }
}
