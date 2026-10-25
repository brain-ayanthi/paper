<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * A `store_id` the signed-in user may actually reach.
 *
 * Security register SEC #11, #25, #33, #41, #51. Every one of these was the
 * same shape: a Form Request validated `store_id` with `exists:stores,id` and
 * nothing more, so the value the browser sent decided which store a journal
 * entry, supplier payment, terminal or user assignment landed in. The screens
 * only ever OFFER accessible stores, which is why it never showed up in use —
 * but the server accepted any id.
 *
 * Super admins pass. An unauthenticated context (console, seeders) passes —
 * there is no user whose access could be exceeded.
 *
 * Use in place of the bare exists rule:
 *
 *   'store_id' => ['required', 'integer', new AccessibleStore],
 *
 * It also confirms the store EXISTS, so it fully replaces `exists:stores,id`
 * rather than sitting alongside it.
 */
class AccessibleStore implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if ($value === null || $value === '') {
            return;   // nullable is the caller's decision, not ours
        }

        $user = auth()->user();

        if (! $user) {
            return;
        }

        if ($user->is_super_admin) {
            if (! \App\Models\Store::query()->whereKey((int) $value)->exists()) {
                $fail(__('validation.exists', ['attribute' => $attribute]));
            }

            return;
        }

        if (! $user->canAccessStore((int) $value)) {
            $fail(__('errors.store_not_accessible'));
        }
    }
}
