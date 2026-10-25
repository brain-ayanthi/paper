<?php

namespace App\Http\Requests\Admin;

use App\Models\ProductLocation;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Validates PATCH /admin/settings/locations.
 *
 * Shape mirrors {@see \App\Models\Company::locationFields()} — which of the
 * four shelf-coordinate fields this company uses, and what it calls them.
 * A pharmacy renames "Aisle" to "Rack"; a warehouse turns all four on.
 */
class LocationSettingsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $rules = [];

        foreach (ProductLocation::COORDINATES as $key) {
            $rules["fields.{$key}.enabled"] = ['sometimes', 'boolean'];
            $rules["fields.{$key}.label"]   = ['nullable', 'string', 'max:24'];
        }

        return $rules;
    }

    /**
     * The exact shape we persist into `company.location_settings`.
     *
     * A blank label is stored as an empty string, which the model reads as
     * "no override" and answers with the translated default — so clearing
     * the box restores the translated name rather than freezing English.
     *
     * @return array<string, mixed>
     */
    public function locationData(): array
    {
        $fields = [];

        foreach (ProductLocation::COORDINATES as $key) {
            $fields[$key] = [
                'enabled' => $this->boolean("fields.{$key}.enabled"),
                'label'   => trim((string) $this->input("fields.{$key}.label", '')),
            ];
        }

        return ['fields' => $fields];
    }

    /**
     * Turning every field off would leave the product editor with a
     * Locations card containing nothing. The model already falls back to
     * defaults in that case, but failing here is clearer than silently
     * ignoring what the owner just did.
     */
    public function withValidator(\Illuminate\Validation\Validator $validator): void
    {
        $validator->after(function ($v) {
            $anyEnabled = false;
            foreach (ProductLocation::COORDINATES as $key) {
                if ($this->boolean("fields.{$key}.enabled")) {
                    $anyEnabled = true;
                    break;
                }
            }

            if (! $anyEnabled) {
                $v->errors()->add('fields', __('settings.locations.errors.none_enabled'));
            }
        });
    }
}
