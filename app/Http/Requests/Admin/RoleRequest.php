<?php

namespace App\Http\Requests\Admin;

use App\Models\Role;
use App\Support\PrivilegeSubset;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validates POST /admin/roles and PATCH /admin/roles/{role}.
 *
 * Beyond shape validation this enforces the privilege-subset rule (SEC #88):
 * `permissions.*` used to accept any id that merely EXISTED, so a
 * `roles.manage` holder could tick a permission they did not hold — including
 * on their own role — and have it next request.
 */
class RoleRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $role = $this->route('role');
        $id   = $role instanceof Role ? $role->id : null;

        return [
            'name' => [
                'required', 'string', 'max:64',
                Rule::unique('roles', 'name')->ignore($id),
            ],
            'description'   => ['nullable', 'string', 'max:191'],
            'permissions'   => ['array'],
            'permissions.*' => ['integer', Rule::exists('permissions', 'id')],
        ];
    }

    /**
     * Reject any permission the actor does not themselves hold. Runs after the
     * shape rules so `permissions.*` ids are already known-valid integers.
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $actor = $this->user();

            if (! $actor) {
                return;
            }

            $beyond = PrivilegeSubset::keysBeyond($this->permissionIds(), $actor);

            if ($beyond !== []) {
                $validator->errors()->add('permissions', __('roles.validation.beyond_own_privileges', [
                    'permissions' => implode(', ', $beyond),
                ]));
            }
        });
    }

    /** @return list<int> Selected permission ids. */
    public function permissionIds(): array
    {
        return array_values(array_unique(array_map('intval', $this->input('permissions', []))));
    }

    /** @return array<string, mixed> */
    public function roleAttributes(): array
    {
        return [
            'name'        => (string) $this->input('name'),
            'description' => $this->input('description'),
        ];
    }
}
