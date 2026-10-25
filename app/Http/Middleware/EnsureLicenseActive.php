<?php

namespace App\Http\Middleware;

use App\Actions\Licensing\RecheckLicense;
use App\Services\Licensing\LicenseClient;
use App\Support\InstallState;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureLicenseActive
{
    public function handle(Request $request, Closure $next): Response
    {
        if (config('pos.license.dev_bypass') || ! config('pos.license.required') || ! config('pos.license.recheck')
            || pos_is_demo() || ! InstallState::isLocked()
            || ! $request->routeIs('admin.*', 'cashier.*', 'kiosk.*')) {
            return $next($request);
        }
        // Recovery routes remain available even while the installation is blocked.
        if ($request->routeIs('admin.settings.license.*')) {
            return $next($request);
        }
        // A copied installation must not use an old APP_URL to borrow another
        // domain's active binding. Scheme/www aliases follow validator normalization.
        if (app(LicenseClient::class)->matchesDomain($request->root(), (string) config('app.url'))) {
            app(RecheckLicense::class)();
            if ((app_license()['status'] ?? 'unverified') === 'valid') {
                return $next($request);
            }
        }
        $message = __('settings.license.blocked');
        if ($request->expectsJson()) {
            return response()->json(['message' => $message, 'code' => 'license_invalid',
                'redirect' => route('admin.settings.license.index')], 423)
                ->header('Cache-Control', 'no-store');
        }

        return response()->view('errors.license', ['message' => $message], 423)
            ->header('Cache-Control', 'no-store');
    }
}
