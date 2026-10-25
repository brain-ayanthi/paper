<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Ends the session of a user who has been deactivated since signing in.
 *
 * The login check (SEC #6) stops a switched-off account from getting a NEW
 * session, but on its own it leaves the hole the finding actually describes:
 * an admin who deactivates a logged-in employee changes nothing until that
 * person happens to log out. Dismissing someone has to take effect now, not
 * whenever their session expires.
 *
 * Deliberately cheap — one boolean read off the already-resolved user, and a
 * no-op for guests, so it can sit on the whole web group. A soft-deleted user
 * resolves to null through the SoftDeletes scope and is handled by the auth
 * middleware as an ordinary guest.
 */
class EnsureUserIsActive
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = Auth::user();

        if ($user && ! $user->is_active) {
            Auth::logout();

            if ($request->hasSession()) {
                $request->session()->invalidate();
                $request->session()->regenerateToken();
            }

            if ($request->expectsJson()) {
                return response()->json(['message' => __('auth.inactive')], 401);
            }

            return redirect('/login')->withErrors(['email' => __('auth.inactive')]);
        }

        return $next($request);
    }
}
