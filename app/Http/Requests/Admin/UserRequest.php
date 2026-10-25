<?php

namespace App\Http\Requests\Admin;

use App\Models\Role;
use App\Models\User;
use App\Support\PrivilegeSubset;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validates POST /admin/users and PATCH /admin/users/{user}.
 *
 * Password is required on create unless the admin chose the setup-link
 * flow (`password_mode = setup_link`); optional on edit (blank = keep).
 *
 * Also enforces the privilege-subset rule on role assignment (SEC #88,
 * vector 2): `stores.*.role_id` only checked that the role EXISTED, so a
 * holder of `users.update` could assign the built-in Admin role — every
 * permission in the system — to anyone, themselves included.
 */
class UserRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $user      = $this->route('user');
        $id        = $user instanceof User ? $user->id : null;
        $isCreate  = $id === null;
        $needsPass = $isCreate && $this->input('password_mode', 'set') !== 'setup_link';

        return [
            'name'  => ['required', 'string', 'max:191'],
            'email' => [
                'required', 'email', 'max:191',
                Rule::unique('users', 'email')->whereNull('deleted_at')->ignore($id),
            ],
            'phone'    => ['nullable', 'string', 'max:32'],
            'locale'   => ['nullable', 'string', 'max:8'],
            'password' => [$needsPass ? 'required' : 'nullable', 'string', \App\Support\PasswordRules::strong()],
            'is_active'      => ['sometimes', 'boolean'],
            'is_super_admin' => ['sometimes', 'boolean'],
            'stores'                 => ['array', 'min:1'],
            'stores.*.store_id'      => ['required', 'integer', new \App\Rules\AccessibleStore],
            'stores.*.role_id'       => ['required', 'integer', Rule::exists('roles', 'id')],
        ];
    }

    public function messages(): array
    {
        return [
            'stores.min'                 => __('users.validation.stores_required'),
            'stores.*.store_id.required' => __('users.validation.store_required'),
            'stores.*.role_id.required'  => __('users.validation.role_required'),
        ];
    }

    /**
     * Block assigning any role that carries a permission the actor lacks —
     * otherwise role assignment is simply a slower permission grant.
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $actor = $this->user();

            if (! $actor || $actor->is_super_admin) {
                return;
            }

            $roleIds = array_unique(array_filter(array_map(
                static fn ($row) => (int) ($row['role_id'] ?? 0),
                (array) $this->input('stores', []),
            )));

            foreach (Role::query()->whereIn('id', $roleIds)->with('permissions:id')->get() as $role) {
                if (! PrivilegeSubset::canAssignRole($actor, $role)) {
                    $validator->errors()->add('stores', __('users.validation.role_beyond_own_privileges', [
                        'role' => $role->name,
                    ]));
                }
            }
        });
    }

    /** @return array<string, mixed> Profile fields for the user record. */
    public function profileData(): array
    {
        return [
            'name'      => (string) $this->input('name'),
            'email'     => (string) $this->input('email'),
            'phone'     => $this->input('phone'),
            'locale'    => $this->input('locale') ?: 'en',
            'is_active' => $this->boolean('is_active', true),
        ];
    }

    /** @return array<int, int> store_id => role_id (last wins on dupes). */
    public function storeRoles(): array
    {
        $out = [];
        foreach ($this->input('stores', []) as $row) {
            if (! empty($row['store_id']) && ! empty($row['role_id'])) {
                $out[(int) $row['store_id']] = (int) $row['role_id'];
            }
        }

        return $out;
    }
}
