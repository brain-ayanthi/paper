<?php

use App\Http\Middleware\ApplyCompanySettings;
use App\Http\Middleware\BlockDemoImportExport;
use App\Http\Middleware\EnsureAppKey;
use App\Http\Middleware\EnsureInstalled;
use App\Http\Middleware\EnsureInstallerStep;
use App\Http\Middleware\EnsureStandardInstaller;
use App\Http\Middleware\InstallerErrorHandler;
use App\Http\Middleware\EnsureStoreSelected;
use App\Http\Middleware\EnsureUserIsActive;
use App\Http\Middleware\PreventResponseCache;
use App\Http\Middleware\RestrictDevRoutes;
use App\Http\Middleware\RestrictDuringInstall;
use App\Http\Middleware\SetLocale;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Support\Facades\Route;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
        then: function () {
            // Installer wizard. `RestrictDuringInstall` (on the group) 404s these
            // once the install lock is written, so on an installed site they're
            // invisible. For dev, state is bootstrapped via
            //   /seed?class=DevBootstrapSeeder
            // which calls the same Actions the wizard would and writes the lock.
            Route::middleware('web')
                ->group(base_path('routes/installer.php'));

            // Developer / maintenance routes (/migrate, /seed, /clear-cache,
            // /storage-link, /phpinfo, demo baseline…). Loaded ONLY when
            // POS_DEV_ROUTES=true, so on a customer install they are never
            // registered — there is no 404-guarded closure to probe, and no
            // anonymous path to migrate:fresh. See config/pos.php 'dev'.
            // The file is stripped from customer packages (build/package.ps1),
            // so check it exists — enabling the flag on a shipped install must
            // yield no dev routes, not a boot failure.
            $devRoutes = base_path('routes/dev.php');
            if (config('pos.dev.routes_enabled', false) && file_exists($devRoutes)) {
                Route::middleware('web')->group($devRoutes);
            }
        },
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->alias([
            'restrict.during.install' => RestrictDuringInstall::class,
            'ensure.installed'        => EnsureInstalled::class,
            'store.selected'          => EnsureStoreSelected::class,
            'set.locale'              => SetLocale::class,
            'no.cache'                => PreventResponseCache::class,
            'installer.errors'        => InstallerErrorHandler::class,
            'installer.step'          => EnsureInstallerStep::class,
            'installer.standard'      => EnsureStandardInstaller::class,
            'dev.route'               => RestrictDevRoutes::class,
        ]);

        // Guarantee an APP_KEY exists before cookie encryption / CSRF run — a
        // fresh upload ships none and the installer's first screen needs one.
        // Prepended to the GLOBAL stack so it executes ahead of the web group's
        // EncryptCookies. No-op (one filled() check) once a key is set.
        $middleware->prepend(EnsureAppKey::class);
        // Apply company-configured settings (name → config('app.name'), …)
        // to every web request so views, mailers, and exceptions see them.
        $middleware->web(prepend: [ApplyCompanySettings::class]);

        // Deactivating a user must take effect immediately, not whenever their
        // session happens to expire (SEC #6). No-op for guests.
        $middleware->web(append: [EnsureUserIsActive::class]);
        $middleware->web(append: [\App\Http\Middleware\EnsureLicenseActive::class]);

        // Demo installs: block bulk import/export on any *.export / *.import*
        // route (no-op on real installs). Appended so it runs after auth.
        $middleware->web(append: [BlockDemoImportExport::class]);

        // Payment gateway webhook routes — Stripe (and future providers)
        // sign the payload at their own signing-secret HMAC, so they
        // can't carry a Laravel CSRF token. The signature verifier in
        // the controller is the auth.
        $middleware->validateCsrfTokens(except: [
            'webhooks/stripe',
            'webhooks/razorpay',
            'webhooks/paystack',
            'webhooks/flutterwave',
            'webhooks/mercado_pago',
            'webhooks/commerce/*',
            'webhooks/whatsapp/*',
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->render(function (\Illuminate\Http\Exceptions\ThrottleRequestsException $e,
            \Illuminate\Http\Request $request) {
            if (! $request->is('install/saas-package', 'install/saas-package/chunk')) {
                return null;
            }

            $message = __('installer.saas_package.rate_limited');
            if ($request->expectsJson()) {
                return response()->json(['message' => $message], 429, $e->getHeaders());
            }

            return redirect()->route('install.saas.package')
                ->withErrors(['delivery' => $message])
                ->withHeaders($e->getHeaders());
        });

        // Provider secrets are nested form fields; Laravel's default list only
        // excludes top-level password fields. Never persist these in session
        // flash data after a validation redirect.
        $exceptions->dontFlash([
            'credentials.consumer_secret',
            'credentials.client_secret',
            'credentials.webhook_secret',
            'saas_purchase_code',
            'saas_owner_token',
            'header_scripts',
            'footer_scripts',
        ]);

        // A permission failure (policy `authorize()` or `abort(403)`) on a
        // normal browser navigation should feel friendly: bounce the user
        // back to the dashboard with a toast rather than dumping the bare
        // "403 · This action is unauthorized" screen. AJAX/API callers still
        // get the raw 403 JSON so the axios layer can react to it.
        $exceptions->render(function (\Throwable $e, \Illuminate\Http\Request $request) {
            $is403 = $e instanceof \Illuminate\Auth\Access\AuthorizationException
                || ($e instanceof \Symfony\Component\HttpKernel\Exception\HttpExceptionInterface
                    && $e->getStatusCode() === 403);

            // Only reshape real browser PAGE navigations (GET) for signed-in
            // users. JSON/AJAX callers, forbidden form actions (POST/PATCH/
            // DELETE — usually AJAX anyway), and guests fall through to the
            // defaults (guests get the login redirect from the auth layer).
            if (! $is403
                || ! $request->isMethod('GET')
                || $request->expectsJson()
                || ! $request->user()) {
                return null;
            }

            return redirect()
                ->route('admin.dashboard')
                ->with('error', __('errors.unauthorized'));
        });
    })->create();
