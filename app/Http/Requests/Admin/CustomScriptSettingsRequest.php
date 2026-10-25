<?php

namespace App\Http\Requests\Admin;

use App\Rules\TrustedScriptMarkup;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class CustomScriptSettingsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) ($this->user()?->is_super_admin
            && $this->user()->can('settings.update'));
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'enabled' => ['sometimes', 'boolean'],
            'include_authenticated' => ['sometimes', 'boolean'],
            'header_scripts' => ['nullable', 'string', 'max:50000', new TrustedScriptMarkup],
            'footer_scripts' => ['nullable', 'string', 'max:50000', new TrustedScriptMarkup],
            'current_password' => ['required', 'current_password'],
            'acknowledge_risk' => [
                Rule::requiredIf(fn () => $this->boolean('enabled')),
                'nullable',
                'accepted',
            ],
        ];
    }

    /** @return array<int, callable> */
    public function after(): array
    {
        return [function (Validator $validator): void {
            if (! $this->boolean('enabled')) {
                return;
            }

            if (trim((string) $this->input('header_scripts')) === ''
                && trim((string) $this->input('footer_scripts')) === '') {
                $validator->errors()->add('header_scripts', __('settings.custom_scripts.validation.code_required'));
            }
        }];
    }

    /** @return array{enabled: bool, include_authenticated: bool, header: string, footer: string} */
    public function scriptData(): array
    {
        return [
            'enabled' => $this->boolean('enabled'),
            'include_authenticated' => $this->boolean('include_authenticated'),
            'header' => trim((string) $this->input('header_scripts')),
            'footer' => trim((string) $this->input('footer_scripts')),
        ];
    }

    /** @return array<string, string> */
    public function attributes(): array
    {
        return [
            'header_scripts' => __('settings.custom_scripts.fields.header'),
            'footer_scripts' => __('settings.custom_scripts.fields.footer'),
            'current_password' => __('settings.custom_scripts.fields.current_password'),
            'acknowledge_risk' => __('settings.custom_scripts.fields.acknowledge'),
        ];
    }
}
