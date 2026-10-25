<?php

namespace App\Http\Controllers\Admin;

use App\Actions\Users\StartEmailChange;
use App\Http\Controllers\Concerns\RespondsJsonOrRedirect;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\UpdatePasswordRequest;
use App\Http\Requests\Admin\UpdateProfileRequest;
use App\Models\Language;
use App\Models\User;
use App\Services\Auth\SessionRegistry;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\View\View;

/**
 * Profile & preferences — the signed-in user's own account screen
 * (the "Profile & preferences" item in the topbar menu). Self-service:
 * no permission gate; a user always edits themselves.
 */
class ProfileController extends Controller
{
    use RespondsJsonOrRedirect;

    public function edit(SessionRegistry $sessions): View
    {
        $user = request()->user();

        return view('admin.profile', [
            'user'      => $user,
            'languages' => Language::query()->active()->ordered()->get(),
            'stores'    => $user->accessibleStores(),
            // Active-devices list (SEC #40). Empty + `sessionsSupported=false`
            // on an install running the `file` session driver, which records
            // no user id and so has nothing to show.
            'sessions'          => $sessions->forUser($user->id, request()->session()->getId()),
            'sessionsSupported' => $sessions->supported(),
        ]);
    }

    /**
     * "Sign out my other devices" (SEC #40).
     *
     * Password re-entry is required — Laravel's standard pattern for this
     * action, and the right one here: the button's whole purpose is to evict
     * somebody who has a session but not the credentials, so accepting it on
     * session alone would let that same person evict the real owner.
     */
    public function revokeOtherSessions(Request $request, SessionRegistry $sessions): JsonResponse|RedirectResponse
    {
        $user = $request->user();

        $request->validate(['password' => ['required', 'string']]);

        if (! Hash::check((string) $request->input('password'), (string) $user->password)) {
            return $this->jsonOrError(
                $request,
                __('account.sessions.wrong_password'),
                route('admin.profile.edit'),
            );
        }

        $count = $sessions->revokeOthers($user->id, $request->session()->getId());

        do_action('auth.session_revoked', $user, $count);

        return $this->jsonOrRedirect(
            $request,
            trans_choice('account.sessions.revoked', $count, ['count' => $count]),
            route('admin.profile.edit'),
        );
    }

    public function update(UpdateProfileRequest $request): JsonResponse|RedirectResponse
    {
        // Read-only on the public demo. Visitors share one account, so an edit
        // here isn't personal — renaming the admin or pointing its email
        // somewhere else changes what every other visitor sees until the
        // nightly reset restores the baseline (see ResetDemoData).
        //
        // Guarded BEFORE the avatar block on purpose: a blocked request must
        // not leave an orphaned upload behind in storage.
        if (pos_is_demo()) {
            return $this->jsonOrError(
                $request,
                __('account.demo.locked'),
                route('admin.profile.edit'),
            );
        }

        $user = $request->user();
        $data = $request->profileData();

        // Email is a two-step change (SEC #10). The address the form carries
        // does not become the login until it is confirmed from a link sent to
        // it — `users.email` is the password-reset channel, so a signed-in
        // session must not be able to redirect it on its own say-so. Strip it
        // out of the ordinary update and start the confirmation instead.
        $requestedEmail = (string) ($data['email'] ?? '');
        unset($data['email']);
        $emailPending = false;

        // Avatar lifecycle — new upload / explicit remove / leave alone.
        if ($request->hasFile('avatar')) {
            // Only swap the path if the write actually succeeded — a failed
            // store() returns false and must never leave a broken reference.
            if ($path = $request->file('avatar')->store('avatars', 'public')) {
                $this->deleteAvatar($user);
                $data['avatar_path'] = $path;
            }
        } elseif ($request->boolean('avatar_remove')) {
            $this->deleteAvatar($user);
            $data['avatar_path'] = null;
        }

        do_action('user.before_update', $user, $data);
        $user->update($data);
        do_action('user.after_update', $user);

        if ($requestedEmail !== '' && strcasecmp($requestedEmail, (string) $user->email) !== 0) {
            $emailPending = app(StartEmailChange::class)($user, $requestedEmail) !== null;
        }

        return $this->jsonOrRedirect(
            $request,
            $emailPending
                ? __('account.flash.updated_email_pending', ['email' => $requestedEmail])
                : __('account.flash.updated'),
            route('admin.profile.edit'),
        );
    }

    /**
     * Confirm a parked email change — `GET /admin/profile/email/confirm/{user}/{token}`
     * (SEC #10).
     *
     * Reached from the link mailed to the NEW address, which is the whole
     * proof. It is deliberately NOT scoped to the signed-in user: the person
     * confirming may be reading the mail on their phone with no session, and
     * the token is the credential either way. Anyone can present it — but only
     * someone who received the mail has it.
     */
    public function confirmEmail(User $user, string $token): RedirectResponse
    {
        if (! StartEmailChange::tokenMatches($user, $token)) {
            return redirect()
                ->route('login')
                ->with('error', __('account.email_change.invalid'));
        }

        // Somebody else may have claimed the address in the meantime. Losing
        // the change is the right outcome; silently colliding is not.
        $taken = User::query()
            ->where('email', $user->pending_email)
            ->whereKeyNot($user->getKey())
            ->exists();

        if ($taken) {
            $this->clearPendingEmail($user);

            return redirect()
                ->route('login')
                ->with('error', __('account.email_change.taken'));
        }

        $user->forceFill([
            'email'                 => $user->pending_email,
            // Confirmed by the round-trip we just completed — this is the one
            // place in the app entitled to stamp it.
            'email_verified_at'     => now(),
            'pending_email'         => null,
            'pending_email_token'   => null,
            'pending_email_sent_at' => null,
        ])->save();

        do_action('user.after_email_changed', $user);

        return redirect()
            ->route('admin.profile.edit')
            ->with('success', __('account.email_change.confirmed', ['email' => $user->email]));
    }

    /** Drop a parked change without applying it. */
    private function clearPendingEmail(User $user): void
    {
        $user->forceFill([
            'pending_email'         => null,
            'pending_email_token'   => null,
            'pending_email_sent_at' => null,
        ])->save();
    }

    public function updatePassword(UpdatePasswordRequest $request): JsonResponse|RedirectResponse
    {
        // The single most damaging write on a public demo. The credentials are
        // published on the login screen (config('pos.demo.credentials')), so
        // one visitor changing the password locks out every other visitor —
        // and the sign-in page keeps advertising a password that no longer
        // works — until the 02:00 reset restores the baseline `users` row.
        if (pos_is_demo()) {
            return $this->jsonOrError(
                $request,
                __('account.demo.password_locked'),
                route('admin.profile.edit'),
            );
        }

        // The `password` attribute is hash-cast on the model.
        $request->user()->update(['password' => $request->input('password')]);

        return $this->jsonOrRedirect(
            $request,
            __('account.flash.password_updated'),
            route('admin.profile.edit'),
        );
    }

    private function deleteAvatar(User $user): void
    {
        if ($user->avatar_path && Storage::disk('public')->exists($user->avatar_path)) {
            Storage::disk('public')->delete($user->avatar_path);
        }
    }
}
