<?php

namespace App\Http\Requests\Admin;

use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class QuotationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'store_id' => ['required', 'integer', Rule::exists('stores', 'id')->where('is_active', true)],
            'customer_id' => ['nullable', 'integer', Rule::exists('customers', 'id')->where('is_active', true)->whereNull('deleted_at')],
            'contact_name' => ['nullable', 'required_without:customer_id', 'string', 'max:191'],
            'contact_email' => ['nullable', 'email:rfc', 'max:191'],
            'contact_phone' => ['nullable', 'string', 'max:32'],
            'billing_address' => ['nullable', 'string', 'max:2000'],
            'shipping_address' => ['nullable', 'string', 'max:2000'],
            'issue_date' => ['required', 'date'],
            'valid_until' => ['nullable', 'date', 'after_or_equal:issue_date'],
            'expected_delivery_date' => ['nullable', 'date', 'after_or_equal:issue_date'],
            'terms' => ['nullable', 'string', 'max:10000'],
            'notes' => ['nullable', 'string', 'max:5000'],
            'internal_notes' => ['nullable', 'string', 'max:5000'],

            'items' => ['required', 'array', 'min:1', 'max:200'],
            'items.*.product_id' => ['required', 'integer', Rule::exists('products', 'id')->where('is_active', true)->whereNull('deleted_at')],
            'items.*.variant_id' => [
                'nullable', 'integer',
                function (string $attribute, mixed $value, Closure $fail) {
                    if (! filled($value) || ! preg_match('/^items\.(\d+)\.variant_id$/', $attribute, $matches)) {
                        return;
                    }
                    $productId = $this->input("items.{$matches[1]}.product_id");
                    $valid = DB::table('product_variants')
                        ->where('id', $value)
                        ->where('product_id', $productId)
                        ->where('is_active', true)
                        ->whereNull('deleted_at')
                        ->exists();
                    if (! $valid) {
                        $fail(__('quotations.validation.variant_product_mismatch'));
                    }
                },
            ],
            'items.*.quantity' => ['required', 'numeric', 'gt:0'],
            'items.*.discount_percent' => ['nullable', 'numeric', 'between:0,100'],
            'items.*.notes' => ['nullable', 'string', 'max:500'],
        ];
    }

    /** @return array<string, mixed> */
    public function quotationData(): array
    {
        return [
            'store_id' => (int) $this->input('store_id'),
            'customer_id' => $this->filled('customer_id') ? (int) $this->input('customer_id') : null,
            'contact_name' => $this->input('contact_name'),
            'contact_email' => $this->input('contact_email'),
            'contact_phone' => $this->input('contact_phone'),
            'billing_address' => $this->input('billing_address'),
            'shipping_address' => $this->input('shipping_address'),
            'issue_date' => $this->input('issue_date'),
            'valid_until' => $this->input('valid_until'),
            'expected_delivery_date' => $this->input('expected_delivery_date'),
            'terms' => $this->input('terms'),
            'notes' => $this->input('notes'),
            'internal_notes' => $this->input('internal_notes'),
            'items' => array_values($this->input('items', [])),
        ];
    }
}
