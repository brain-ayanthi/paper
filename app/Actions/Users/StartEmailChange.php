<?php

namespace App\Actions\Users;

use App\Mail\ConfirmEmailChangeMail;
use App\Models\User;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

/**
 * Park a requested email address and send a confirmation link to it —
 * security register SEC #10 (defect #11).
 *
 * The address the user typed does NOT become their login until they open the
 * link we send to it. Two reasons, and the second is the one that matters:
 *
 *  1. A typo would otherwise lock them out of their own password reset.
 *  2. `users.email` is the account-recovery channel. Letting a signed-in
 *     session move it — as the old code did, marking the result verified
 *     without anyone ever proving control — turns "borrowed a logged-in
 *     till for thirty seconds" into a full account takeover, with the real
 *     owner deprived of the notification that would have warned them.
 *
 * Hooks: `user.before_email_change` / `user.after_email_change_requested`.
 */
class StartEmailChange
{
    /** How long the confirmation link stays usable. */
    public const EXPIRES_HOURS = 24;

    /**
     * Returns the plaintext token (for tests and for the caller to log), or
     * null when the address matches the current one and nothing was started.
     */
    public function __invoke(User $user, string $newEmail): ?string
    {
        $newEmail = trim($newEmail);

        if ($newEmail === '' || strcasecmp($newEmail, (string) $user->email) === 0) {
            return null;
        }

        do_action('user.before_email_change', $user, $newEmail);

        $token = Str::random(64);

        $user->forceFill([
            'pending_email'         => $newEmail,
            'pending_email_token'   => hash('sha256', $token),
            'pending_email_sent_at' => now(),
        ])->save();

        // A mail failure must not lose the user their profile edit — the name
        // and preferences in the same form have already been saved by the
        // time we get here. Park the change, report the failure, and let them
        // press save again.
        try {
            Mail::to($newEmail)->send(new ConfirmEmailChangeMail($user, $token));
        } catch (\Throwable $e) {
            Log::warning('Could not send the email-change confirmation.', [
                'user_id' => $user->id,
                'error'   => $e->getMessage(),
            ]);
        }

        do_action('user.after_email_change_requested', $user);

        return $token;
    }

    /**
     * Does this plaintext token match the user's pending change, and is it
     * still inside the window?
     */
    public static function tokenMatches(User $user, string $token): bool
    {
        if (! $user->pending_email || ! $user->pending_email_token) {
            return false;
        }

        if (! $user->pending_email_sent_at
            || $user->pending_email_sent_at->addHours(self::EXPIRES_HOURS)->isPast()) {
            return false;
        }

        return hash_equals((string) $user->pending_email_token, hash('sha256', $token));
    }
}
