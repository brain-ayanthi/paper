<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ConvertQuotationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        if (! $this->has('payments')) {
            $this->merge(['payments' => []]);
        }
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'payments' => ['present', 'array', 'max:10'],
            'payments.*.payment_method_id' => [
                'required',
                'integer',
                Rule::exists('payment_methods', 'id')->where(fn ($query) => $query
                    ->where('is_active', true)
                    ->where(fn ($providers) => $providers
                        ->whereNull('provider')
                        ->orWhere('provider', '')
                        ->orWhere('provider', 'none'))),
            ],
            'payments.*.amount' => ['required', 'numeric', 'gt:0'],
            'payments.*.reference' => ['nullable', 'string', 'max:191'],
        ];
    }

    /** @return array<int, array<string, mixed>> */
    public function paymentData(): array
    {
        return array_values($this->input('payments', []));
    }
}
