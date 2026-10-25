<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

class InstallPluginRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->is_super_admin;
    }

    public function rules(): array
    {
        return [
            'current_password' => ['required', 'current_password'],
            'plugin' => [
                'required', 'file', 'mimes:zip',
                'max:'.(int) config('plugins.upload_max_kb', 51200),
            ],
        ];
    }

    public function messages(): array
    {
        return [
            'plugin.required' => __('plugins.validation.file_required'),
            'plugin.file' => __('plugins.validation.file_invalid'),
            'plugin.uploaded' => __('plugins.validation.file_invalid'),
            'plugin.mimes' => __('plugins.validation.file_zip'),
            'plugin.max' => __('plugins.validation.file_max'),
            'current_password.required' => __('plugins.validation.password_required'),
            'current_password.current_password' => __('plugins.validation.password_incorrect'),
        ];
    }
}
