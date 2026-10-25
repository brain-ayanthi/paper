<?php

namespace App\Http\Requests\Admin\Commerce;

use App\Models\CommerceStoreMapping;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SaveCommerceStoreMappingsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('commerce.manage') ?? false;
    }

    public function rules(): array
    {
        return [
            'mappings' => ['required', 'array'],
            'mappings.*.store_id' => ['required', 'integer', 'distinct', 'exists:stores,id'],
            'mappings.*.remote_location_id' => ['nullable', 'string', 'max:191'],
            'mappings.*.remote_location_name' => ['nullable', 'string', 'max:255'],
            'mappings.*.inventory_mode' => ['required', Rule::in(CommerceStoreMapping::INVENTORY_MODES)],
            'mappings.*.fulfillment_priority' => ['required', 'integer', 'min:1', 'max:9999'],
            'mappings.*.safety_stock' => ['required', 'numeric', 'min:0'],
            'mappings.*.sync_inventory' => ['required', 'boolean'],
            'mappings.*.fulfill_orders' => ['required', 'boolean'],
            'mappings.*.is_active' => ['required', 'boolean'],
        ];
    }

    protected function prepareForValidation(): void
    {
        $mappings = collect((array) $this->input('mappings', []))
            ->map(function ($mapping): array {
                $mapping = (array) $mapping;
                foreach (['sync_inventory', 'fulfill_orders', 'is_active'] as $key) {
                    $mapping[$key] = filter_var($mapping[$key] ?? false, FILTER_VALIDATE_BOOLEAN);
                }

                return $mapping;
            })
            ->values()
            ->all();

        $this->merge(['mappings' => $mappings]);
    }

    public function attributes(): array
    {
        return __('commerce.validation.attributes');
    }
}
