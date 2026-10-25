<?php

namespace App\Support;

use Illuminate\Validation\Rules\Password;

/**
 * The one password policy — security register SEC #8 (defect #4).
 *
 * `docs/features/auth-users.md` asks for at least 10 characters with letters,
 * numbers and symbols. The installer's admin step already enforced exactly
 * that, but every other place a password could be set settled for 8 characters
 * with no character-mix requirement: the password-reset form, the admin
 * user-create/edit form, and the self-service profile change. So the strong
 * rule applied once, at install, and every route a user could actually take
 * afterwards was weaker — including the reset flow an attacker would target.
 *
 * Defined here so the four call sites cannot drift apart again.
 */
class PasswordRules
{
    /** Minimum length, per the auth-users spec. */
    public const MIN_LENGTH = 10;

    /**
     * The rule for anywhere a password is chosen or changed.
     *
     * Deliberately does NOT enable `uncompromised()` (the Have-I-Been-Pwned
     * lookup): this product is self-hosted and must work on installs with no
     * outbound internet, and CLAUDE.md rule 1 allows no callbacks beyond the
     * three named ones. A blocked lookup would either fail the request or
     * silently pass, and neither is a good default here.
     */
    public static function strong(): Password
    {
        return Password::min(self::MIN_LENGTH)
            ->letters()
            ->numbers()
            ->symbols();
    }
}
