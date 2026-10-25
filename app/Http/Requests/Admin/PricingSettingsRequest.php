<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class PricingSettingsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) ($this->user()?->hasPermission('settings.update'));
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'pricing_method' => ['required', Rule::in(['product_based', 'batch_wise'])],
            'auto_apply_markup_on_receive' => ['nullable', 'boolean'],
        ];
    }

    /** @return array<string, mixed> */
    public function pricingData(): array
    {
        return [
            'pricing_method' => (string) $this->validated('pricing_method'),
            'auto_apply_markup_on_receive' => $this->boolean('auto_apply_markup_on_receive'),
        ];
    }
}
