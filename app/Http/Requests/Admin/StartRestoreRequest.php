<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validates POST /admin/settings/restore — actually starting the restore.
 * `token` references the staged source from the validate step; `confirmation`
 * must be the literal RESTORE word the operator typed.
 */
class StartRestoreRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->can('backup.restore');
    }

    protected function prepareForValidation(): void
    {
        $this->merge(['confirmation' => trim((string) $this->input('confirmation'))]);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'token'              => ['required', 'string'],
            'confirmation'       => ['required', Rule::in(['RESTORE'])],
            'pre_restore_backup' => ['sometimes', 'boolean'],
            // Explicit acceptance of an archive whose provenance we cannot
            // confirm (SEC #20). Only consulted when the archive is unsigned
            // or carries another install signature.
            'accept_unverified'  => ['sometimes', 'boolean'],
        ];
    }
}
