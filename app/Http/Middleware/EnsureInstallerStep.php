<?php

namespace App\Http\Middleware;

use App\Support\InstallState;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Refuse an installer step whose prerequisites are not done — security
 * register SEC #22 (defect #79).
 *
 * The wizard tracked which steps had completed but never CHECKED. The pages
 * are unauthenticated by necessity (there is no user yet), so anyone who could
 * reach a half-installed site could POST straight to `/install/complete`,
 * which writes the install lock — permanently 404-ing every installer route.
 * The result is an install with no admin account and no way to create one: a
 * denial of service that a legitimate operator cannot undo from the UI.
 *
 * Used as `installer.step:db_configured` — the state key(s) that must already
 * be true, comma-separated. A failed check sends the operator back to the
 * earliest incomplete step rather than erroring at them.
 */
class EnsureInstallerStep
{
    /**
     * Where to send someone who has skipped ahead — the route that actually
     * satisfies each milestone, so the redirect is always useful.
     */
    private const STEP_ROUTES = [
        'db_configured'  => 'install.database',
        'migrations_run' => 'install.database',
        'admin_created'  => 'install.admin',
    ];

    public function handle(Request $request, Closure $next, string ...$required): Response
    {
        foreach ($required as $key) {
            if (InstallState::get($key) === true) {
                continue;
            }

            $route = self::STEP_ROUTES[$key] ?? 'install.welcome';

            if ($request->expectsJson()) {
                return response()->json([
                    'message' => __('installer.errors.step_out_of_order'),
                    'redirect' => route($route),
                ], 422);
            }

            return redirect()->route($route)
                ->with('error', __('installer.errors.step_out_of_order'));
        }

        return $next($request);
    }
}
