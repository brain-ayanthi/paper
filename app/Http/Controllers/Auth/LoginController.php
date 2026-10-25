<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\LoginRequest;
use App\Models\Company;
use App\Services\Auth\SessionRegistry;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;

/**
 * Session-based authentication for the admin app.
 * MFA, password reset, super-admin enforcement, role-permission checks etc.
 * are deferred — see docs/features/auth-users.md for the full spec.
 */
class LoginController extends Controller
{
    public function show(): View|RedirectResponse
    {
        return Auth::check()
            ? redirect()->intended('/admin')
            : view('auth.login');
    }

    public function attempt(LoginRequest $request): RedirectResponse|JsonResponse
    {
        $request->ensureIsNotRateLimited();

        $credentials = $request->only('email', 'password');
        $remember    = $request->boolean('remember');

        if (! Auth::attempt($credentials, $remember)) {
            $request->hitRateLimit();

            if ($request->expectsJson()) {
                return response()->json([
                    'message' => __('auth.failed'),
                    'errors'  => ['email' => [__('auth.failed')]],
                ], 422);
            }

            return back()
                ->withErrors(['email' => __('auth.failed')])
                ->withInput($request->only('email', 'remember'));
        }

        // Two-factor is not built (SEC #39 / defect #5). The columns and the
        // settings toggle exist, and the docs describe the flow, but there is
        // no challenge step — so an account with MFA switched on was logged
        // straight in. That is worse than not offering it: the owner believes
        // a second factor is protecting the account when nothing is.
        //
        // Until the challenge ships, fail CLOSED — refuse the login and say
        // why. An operator who turned the flag on can turn it off; nobody is
        // silently unprotected.
        if (Auth::user()?->mfa_enabled) {
            Auth::logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            if ($request->expectsJson()) {
                return response()->json([
                    'message' => __('auth.mfa_unavailable'),
                    'errors'  => ['email' => [__('auth.mfa_unavailable')]],
                ], 422);
            }

            return back()
                ->withErrors(['email' => __('auth.mfa_unavailable')])
                ->withInput($request->only('email', 'remember'));
        }

        // The password was right, but the account may since have been switched
        // off. Without this, deactivating a user did nothing — a dismissed or
        // suspended employee kept full access (SEC #6 / defect #1).
        //
        // Soft-deleted users are already excluded upstream: the Eloquent user
        // provider queries through the model, and User uses SoftDeletes, so a
        // deleted account never authenticates in the first place.
        if (! Auth::user()?->is_active) {
            Auth::logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();
            $request->hitRateLimit();

            if ($request->expectsJson()) {
                return response()->json([
                    'message' => __('auth.inactive'),
                    'errors'  => ['email' => [__('auth.inactive')]],
                ], 422);
            }

            return back()
                ->withErrors(['email' => __('auth.inactive')])
                ->withInput($request->only('email', 'remember'));
        }

        $request->clearRateLimit();
        $request->session()->regenerate();

        // Concurrent-login policy (SEC #40). Documented in
        // docs/features/auth-users.md §Settings → Security and never read by
        // anything — an account could be signed in on any number of devices
        // regardless of the setting. Enforced here, AFTER the regenerate, so
        // the id we keep is this login's final one.
        if (Company::current()?->enforce_single_session) {
            app(SessionRegistry::class)->revokeOthers(
                (int) Auth::id(),
                $request->session()->getId(),
            );
        }

        if ($request->expectsJson()) {
            $intended = session()->pull('url.intended', '/admin');
            return response()->json(['redirect' => $intended]);
        }

        return redirect()->intended('/admin');
    }

    public function destroy(Request $request): RedirectResponse
    {
        app(\App\Services\TerminalReservation::class)->release($request);
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect('/login');
    }
}
