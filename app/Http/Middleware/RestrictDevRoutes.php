<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Guards the developer / maintenance routes in routes/dev.php.
 *
 * That file is only loaded at all when `pos.dev.routes_enabled` is true, so on
 * a customer install these routes never reach the router. This middleware is
 * the second line: it re-checks the flag (so a cached route table built while
 * the flag was on cannot outlive it), and it demands a second factor for the
 * destructive routes.
 *
 * Usage:
 *   ->middleware('dev.route')                — read-only / repair routes
 *   ->middleware('dev.route:destructive')    — anything that drops or
 *                                              overwrites data
 *   ->middleware('dev.route:destructive_if_fresh')
 *                                            — /migrate, which is additive
 *                                              until ?fresh=1 drops the schema
 *
 * The destructive second factor is `?token=` matching POS_DEV_TOKEN, or a
 * signed-in super admin. The token path is required because the very first
 * /migrate runs against an empty database where no user exists yet; the
 * super-admin path means a day-to-day dev never has to paste a token.
 */
class RestrictDevRoutes
{
    public function handle(Request $request, Closure $next, ?string $level = null): Response
    {
        // Belt and braces: routes/dev.php is only loaded when this is true,
        // but a stale `route:cache` could have baked the routes in.
        if (! config('pos.dev.routes_enabled', false)) {
            abort(404);
        }

        if ($this->isDestructive($request, $level) && ! $this->authorizeDestructive($request)) {
            abort(403, 'This route needs ?token=<POS_DEV_TOKEN> or a signed-in super admin.');
        }

        return $next($request);
    }

    /**
     * `destructive_if_fresh` covers /migrate: adding pending tables is routine,
     * but `?fresh=1` drops every table and needs the second factor.
     */
    private function isDestructive(Request $request, ?string $level): bool
    {
        return match ($level) {
            'destructive'          => true,
            'destructive_if_fresh' => $request->boolean('fresh'),
            default                => false,
        };
    }

    /**
     * A signed-in super admin, or a constant-time match against POS_DEV_TOKEN.
     * An empty configured token never matches — an unset secret must not become
     * a blank skeleton key.
     */
    private function authorizeDestructive(Request $request): bool
    {
        if (Auth::check() && (bool) (Auth::user()->is_super_admin ?? false)) {
            return true;
        }

        $expected = (string) config('pos.dev.token', '');
        $supplied = (string) $request->query('token', '');

        return $expected !== '' && hash_equals($expected, $supplied);
    }
}
