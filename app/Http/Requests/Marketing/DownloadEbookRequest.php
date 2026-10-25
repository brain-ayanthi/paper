<?php

namespace App\Http\Requests\Marketing;

use App\Support\Countries;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class DownloadEbookRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'name' => trim((string) $this->input('name')),
            'email' => strtolower(trim((string) $this->input('email'))),
            'country' => strtoupper(trim((string) $this->input('country'))),
        ]);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'min:2', 'max:120'],
            'email' => ['required', 'string', 'email:rfc', 'max:254'],
            'country' => ['required', 'string', 'size:2', Rule::in(array_keys(Countries::all('en')))],
            // Honeypot: real visitors never see or focus this field.
            'website' => ['nullable', 'string', 'max:0'],
        ];
    }

    /** @return array<string, string> */
    public function attributes(): array
    {
        return [
            'name' => __('landing.ebook.fields.name'),
            'email' => __('landing.ebook.fields.email'),
            'country' => __('landing.ebook.fields.country'),
        ];
    }
}
