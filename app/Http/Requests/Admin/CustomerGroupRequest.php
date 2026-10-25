<?php

namespace App\Http\Requests\Admin;

use App\Models\CustomerGroup;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class CustomerGroupRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $row = $this->route('customerGroup');
        $id  = $row instanceof CustomerGroup ? $row->id : null;

        return [
            'name'                     => ['required', 'string', 'max:100', Rule::unique('customer_groups', 'name')->ignore($id)],
            'default_discount_percent' => ['nullable', 'numeric', 'between:0,100'],
            // Loyalty earn multiplier for the tier. Blank = 1× (plain
            // earn); an explicit 0 is allowed and means "earns nothing".
            'loyalty_multiplier'       => ['nullable', 'numeric', 'between:0,100'],
            'is_active'                => ['sometimes', 'boolean'],
        ];
    }

    /** @return array<string, string> */
    public function attributes(): array
    {
        return [
            'default_discount_percent' => __('customers.groups.fields.discount'),
            'loyalty_multiplier'       => __('customers.groups.fields.loyalty_multiplier'),
        ];
    }

    /** @return array<string, mixed> */
    public function persistedAttributes(): array
    {
        $data = $this->validated();
        $data['is_active'] = $this->boolean('is_active', true);
        $data['default_discount_percent'] = $data['default_discount_percent'] ?? null;
        // '' from an untouched form field means "not configured" → NULL,
        // which the model reads as 1×. Only a real 0 means "earns nothing".
        $data['loyalty_multiplier'] = ($data['loyalty_multiplier'] ?? '') === '' ? null : $data['loyalty_multiplier'];
        return $data;
    }
}
