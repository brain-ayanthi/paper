<?php

/*
|--------------------------------------------------------------------------
| Developer / maintenance routes
|--------------------------------------------------------------------------
|
| The web equivalents of the artisan commands, for shared hosting where SSH
| is not available. THIS FILE IS ONLY LOADED WHEN `pos.dev.routes_enabled`
| IS TRUE (POS_DEV_ROUTES=true in .env) — see bootstrap/app.php. On a customer
| install the routes below do not exist in the router at all.
|
| Never ship a .env with POS_DEV_ROUTES=true.
|
| Two guard levels, both applied by App\Http\Middleware\RestrictDevRoutes:
|
|   dev.route              — read-only or idempotent repair. Flag only.
|   dev.route:destructive  — drops or overwrites data. Flag PLUS either
|                            ?token=<POS_DEV_TOKEN> or a signed-in super admin.
|
| The token exists for the empty-database case: the very first /migrate runs
| before any user record exists, so there is nobody to authenticate as.
|
*/

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Route;

// ---------------------------------------------------------------------------
// Cache + filesystem repair
// ---------------------------------------------------------------------------

Route::get('/clear-cache', function () {
    Artisan::call('optimize:clear');
    Artisan::call('cache:clear');
    Artisan::call('config:clear');
    Artisan::call('route:clear');
    Artisan::call('view:clear');
    // Reset PHP OPcache too — otherwise edits to lang files, controllers,
    // etc. won't be picked up until Apache restarts. CLI artisan calls
    // bypass OPcache so they always see fresh, but the web SAPI doesn't.
    //
    // Report the outcome rather than swallowing it: opcache_reset() returns
    // false when `opcache.restrict_api` blocks the caller, and a silent
    // failure here reads as "I cleared the cache and it didn't help".
    $pluginRoot = rtrim((string) config('plugins.directory'), '\\/').DIRECTORY_SEPARATOR.'hyper-pos-saas';
    $pluginFiles = [
        'ControlPlaneApplication.php' => $pluginRoot.DIRECTORY_SEPARATOR.'src'.DIRECTORY_SEPARATOR.'ControlPlane'.DIRECTORY_SEPARATOR.'ControlPlaneApplication.php',
        'PdoControlPlaneStore.php' => $pluginRoot.DIRECTORY_SEPARATOR.'src'.DIRECTORY_SEPARATOR.'ControlPlane'.DIRECTORY_SEPARATOR.'PdoControlPlaneStore.php',
        'SharedPlatformInstaller.php' => $pluginRoot.DIRECTORY_SEPARATOR.'src'.DIRECTORY_SEPARATOR.'SharedHosting'.DIRECTORY_SEPARATOR.'SharedPlatformInstaller.php',
    ];
    $pluginDiagnostics = [];
    foreach ($pluginFiles as $name => $file) {
        clearstatcache(true, $file);
        $exists = is_file($file) && is_readable($file);
        $invalidated = $exists && function_exists('opcache_invalidate') ? @opcache_invalidate($file, true) : null;
        $pluginDiagnostics[] = $name.': '.($exists ? hash_file('sha256', $file) : 'MISSING')
            .($invalidated === null ? '' : ' / OPcache invalidate '.($invalidated ? 'OK' : 'FAILED'));
    }

    $manifest = $pluginRoot.DIRECTORY_SEPARATOR.'plugin.json';
    $version = '(manifest missing)';
    if (is_file($manifest) && is_readable($manifest)) {
        $decoded = json_decode((string) file_get_contents($manifest), true);
        $version = is_array($decoded) && is_string($decoded['version'] ?? null) ? $decoded['version'] : '(invalid manifest)';
    }

    $opcache = 'not available in this SAPI';
    if (function_exists('opcache_reset')) {
        $opcache = opcache_reset() ? 'reset OK' : 'RESET FAILED (opcache.restrict_api? file_cache?)';
    }

    // Visiting /clear-cache directly has no referrer to redirect "back"
    // to, so return a plain confirmation that always works. The source hashes
    // prove which add-on files are on disk; the next request loads invalidated
    // bytecode and can be compared with the publisher-provided release hashes.
    $details = [
        'Cache cleared successfully.',
        'OPcache: '.$opcache,
        'SaaS plugin version: '.$version,
        'SaaS plugin root: '.$pluginRoot,
        ...$pluginDiagnostics,
        'Open the setup page in a new request after this response.',
    ];

    return response(implode("\n", $details)."\n", 200)
        ->header('Content-Type', 'text/plain');
})->middleware('dev.route');

// Answers "does the server actually have this class, and where is it looking?"
// when a `Class/Trait "…" not found` fatal survives /clear-cache. The two
// causes look identical from the browser — a stale OPcache entry, or a deploy
// that never uploaded the file — and this separates them: `base_path` shows
// WHICH filesystem is serving, and `file exists` answers it outright.
//
//   /diagnose-class?class=App\Http\Controllers\Concerns\ResolvesExportFormat
Route::get('/diagnose-class', function (\Illuminate\Http\Request $request) {
    $class  = trim((string) $request->query('class', ''));
    $loader = null;

    // The registered composer loader — its findFile() is the exact same
    // lookup that failed during the fatal, so ask it rather than guessing
    // the path from the namespace.
    foreach (spl_autoload_functions() ?: [] as $fn) {
        if (is_array($fn) && ($fn[0] ?? null) instanceof \Composer\Autoload\ClassLoader) {
            $loader = $fn[0];
            break;
        }
    }

    $file   = ($loader && $class !== '') ? $loader->findFile($class) : false;
    $status = function_exists('opcache_get_status') ? @opcache_get_status(false) : null;

    $lines = [
        'Class asked for:   '.($class ?: '(none — pass ?class=Fully\\Qualified\\Name)'),
        'base_path():       '.base_path(),
        'Composer loader:   '.($loader ? 'found' : 'NOT FOUND'),
        'Resolves to file:  '.($file ?: '(loader has no mapping — check the namespace)'),
        'File exists?       '.($file && file_exists($file) ? 'YES' : 'NO'),
        'File readable?     '.($file && is_readable($file) ? 'YES' : 'NO'),
        'File size / mtime: '.($file && file_exists($file)
            ? filesize($file).' bytes / '.date('Y-m-d H:i:s', filemtime($file))
            : '—'),
        '',
        'class_exists:      '.($class !== '' && class_exists($class) ? 'YES' : 'no'),
        'trait_exists:      '.($class !== '' && trait_exists($class) ? 'YES' : 'no'),
        'interface_exists:  '.($class !== '' && interface_exists($class) ? 'YES' : 'no'),
        '',
        'OPcache enabled:   '.($status['opcache_enabled'] ?? false ? 'YES' : 'no'),
        // The on-disk bytecode cache survives opcache_reset(), so a stale
        // entry here explains "cleared the cache, still broken".
        'OPcache file_cache: '.(ini_get('opcache.file_cache') ?: '(off)'),
        'validate_timestamps: '.(ini_get('opcache.validate_timestamps') ?: '0'),
        'restrict_api:      '.(ini_get('opcache.restrict_api') ?: '(off)'),
    ];

    return response('<pre>'.e(implode("\n", $lines)).'</pre>')
        ->header('Content-Type', 'text/html');
})->middleware('dev.route');

// ---------------------------------------------------------------------------
// Schema + data
// ---------------------------------------------------------------------------

//   /migrate           — run pending migrations (safe to re-hit). Additive, so
//                        it stays as frictionless as it has always been.
//   /migrate?fresh=1   — drop all tables and re-run everything (DESTRUCTIVE,
//                        so it also needs ?token= or a super-admin session)
//
// After a `fresh` we ALSO clear caches and wipe file sessions. Without
// that the next request can hang on Windows: the stale session cookie
// points to a user_id that no longer exists, the file session handler
// holds a lock while the auth middleware tries to load that ghost user,
// and config/view/route caches may still reference the just-dropped
// schema. Clearing everything makes the next page load clean.
Route::get('/migrate', function (\Illuminate\Http\Request $request) {
    @set_time_limit(0);
    @ini_set('memory_limit', '512M');

    // Release the session file lock BEFORE the long-running command so
    // other browser tabs aren't blocked while migrations run.
    if ($request->hasSession()) {
        $request->session()->save();
    }

    $isFresh = $request->boolean('fresh');
    $command = $isFresh ? 'migrate:fresh' : 'migrate';
    Artisan::call($command, ['--force' => true]);
    $output = Artisan::output();

    // Make sure public/storage → storage/app/public exists so uploaded brand
    // logos / product images render. Idempotent, and it clears a broken link
    // left behind by a move or a restore before re-creating it.
    $link = app(\App\Actions\Installer\LinkPublicStorage::class)();
    $output .= "\nstorage:link — {$link['status']}".($link['message'] ? ": {$link['message']}" : '');

    if ($isFresh) {
        // 1. Drop every framework cache that may reference the old schema.
        Artisan::call('optimize:clear');
        $output .= "\n".Artisan::output();

        // 2. Wipe file sessions so a stale logged-in cookie doesn't try
        //    to resolve a deleted user on the next request.
        $sessionsPath = storage_path('framework/sessions');
        if (is_dir($sessionsPath)) {
            foreach (glob($sessionsPath.'/*') as $file) {
                if (is_file($file) && basename($file) !== '.gitignore') {
                    @unlink($file);
                }
            }
            $output .= "\nCleared file sessions.\n";
        }
    }

    // Reset OPcache so the web SAPI (Apache) picks up any code that shipped
    // with this migration — e.g. policy/permission changes. CLI artisan
    // bypasses OPcache, so without this the browser can keep running stale
    // bytecode (old policies, etc.) and appear to ignore the new logic.
    if (function_exists('opcache_reset')) {
        @opcache_reset();
        $output .= "\nOPcache reset.\n";
    }

    return '<pre>'.e($output).'</pre>';
})->middleware('dev.route:destructive_if_fresh');

//   /seed              — run DatabaseSeeder (idempotent; safe to re-hit)
//   /seed?class=Foo    — run a specific seeder (e.g. /seed?class=PermissionsSeeder)
//
// Destructive: an arbitrary seeder class name is effectively arbitrary code
// selection, and demo seeders rewrite live tables.
Route::get('/seed', function (\Illuminate\Http\Request $request) {
    @set_time_limit(0);
    @ini_set('memory_limit', '512M');

    // Release the session lock before the long-running command so other
    // browser tabs can keep responding while the seeder runs.
    if ($request->hasSession()) {
        $request->session()->save();
    }

    $params = ['--force' => true];
    if ($class = $request->query('class')) {
        $params['--class'] = $class;
    }
    Artisan::call('db:seed', $params);
    $output = Artisan::output();

    // Reset OPcache so the web SAPI sees any code changes that shipped with
    // this seed (CLI artisan bypasses OPcache; Apache can otherwise keep
    // running stale bytecode).
    if (function_exists('opcache_reset')) {
        @opcache_reset();
        $output .= "\nOPcache reset.\n";
    }

    return '<pre>'.e($output).'</pre>';
})->middleware('dev.route:destructive');

// ---------------------------------------------------------------------------
// Environment inspection
// ---------------------------------------------------------------------------

// Dump what THIS PHP SAPI (the one Apache is using) sees. Use this when CLI
// `php -m` and the installer disagree about loaded extensions.
Route::get('/phpinfo', function () {
    return response('<pre>'.e(implode("\n", [
        'SAPI:          '.php_sapi_name(),
        'PHP version:   '.PHP_VERSION,
        'Loaded INI:    '.(php_ini_loaded_file() ?: '(none)'),
        'Scanned INIs:  '.(php_ini_scanned_files() ?: '(none)'),
        '',
        'zip extension loaded?      '.(extension_loaded('zip') ? 'YES' : 'NO'),
        'intl extension loaded?     '.(extension_loaded('intl') ? 'YES' : 'NO'),
        'fileinfo extension loaded? '.(extension_loaded('fileinfo') ? 'YES' : 'NO'),
        '',
        '--- All loaded extensions ---',
        implode("\n", get_loaded_extensions()),
    ])).'</pre>')->header('Content-Type', 'text/html');
})->middleware('dev.route');

// ---------------------------------------------------------------------------
// Gateway refund diagnostics
// ---------------------------------------------------------------------------

// Prove the gateway refund reversal without real gateway keys. Stamps the
// newest (or ?sale=ID) sale as gateway-paid, runs a real 1-unit refund with a
// fake gateway, and links you to the sale so the "Reversed at gateway" badge
// is visible. Add ?fail=1 to see the failed/retry path.
//
// Destructive: it mutates a real sale and posts a real refund row.
Route::get('/simulate-gateway-refund', function (\Illuminate\Http\Request $request) {
    $params = [];
    if ($sale = $request->query('sale')) {
        $params['sale'] = $sale;
    }
    if ($request->boolean('fail')) {
        $params['--fail'] = true;
    }

    Artisan::call('pos:simulate-gateway-refund', $params);

    return '<pre>'.e(Artisan::output()).'</pre>';
})->middleware('dev.route:destructive');

// Diagnose why a gateway refund reversal did / didn't happen. Dumps the sale's
// payment rows + the latest refund's bucketing and gateway reversal status.
// Defaults to the most recent refund; ?sale=ID targets one.
//
// Destructive: ?reverse=1 issues a real gateway reversal, and the dump itself
// is customer payment data.
Route::get('/debug-refund', function (\Illuminate\Http\Request $request) {
    $out = [];
    $hasCol = \Illuminate\Support\Facades\Schema::hasColumn('sale_returns', 'gateway_refund_status');
    $out[] = 'Migration applied (gateway_refund_status column): '.($hasCol ? 'YES' : 'NO  ← run /migrate first!');

    $saleId = $request->query('sale');
    $return = $saleId
        ? \App\Models\SaleReturn::where('sale_id', (int) $saleId)->latest('id')->first()
        : \App\Models\SaleReturn::latest('id')->first();

    if (! $return) {
        $out[] = "\nNo refunds recorded yet. Process a refund first, then reload this page.";

        return response('<pre>'.e(implode("\n", $out)).'</pre>');
    }

    // ?reverse=1 → run the gateway reversal on this refund now (dev convenience
    // for refunds recorded before the fix landed). Idempotent + never throws.
    if ($request->boolean('reverse')) {
        app(\App\Actions\Sales\ReverseGatewayCharge::class)($return->fresh());
        $return->refresh();
        $out[] = "\n>> Ran gateway reversal now → status=".var_export($return->gateway_refund_status, true);
    }

    $sale = $return->sale;
    $out[] = "\nSale #{$sale->id}   status={$sale->status}   grand_total={$sale->grand_total}";
    $out[] = '--- Payments captured on this sale ---';
    foreach (\App\Models\SalePayment::where('sale_id', $sale->id)->get() as $p) {
        $m = $p->paymentMethod;
        $out[] = sprintf(
            "  %s (type=%s, provider=%s)  amount=%s\n     gateway_provider=%s  gateway_payment_id=%s",
            $m?->name ?? '?', $m?->type ?? '?', $m?->provider ?? '?', $p->amount,
            var_export($p->gateway_provider, true), var_export($p->gateway_payment_id, true),
        );
    }

    $rm = \App\Models\PaymentMethod::find($return->refund_method_id);
    $out[] = "\n--- Latest refund {$return->number} ---";
    $out[] = '  refund_method='.($rm?->name ?? '(none)').'  (type='.($rm?->type ?? '-').', code='.($rm?->code ?? '-').')';
    $out[] = '  refunded_in_cash            = '.$return->refunded_in_cash;
    $out[] = '  refunded_to_store_credit    = '.$return->refunded_to_store_credit;
    $out[] = '  refunded_to_original_method = '.$return->refunded_to_original_method;
    $out[] = '  gateway_refund_status  = '.var_export($return->gateway_refund_status, true);
    $out[] = '  gateway_refund_attempts= '.$return->gateway_refund_attempts;
    $out[] = '  gateway_refund_error   = '.var_export($return->gateway_refund_error, true);

    $out[] = "\n=== DIAGNOSIS ===";
    $toOriginal = bccomp((string) ($return->refunded_to_original_method ?? '0'), '0', 4) > 0;
    $hasGatewayPayment = \App\Models\SalePayment::where('sale_id', $sale->id)->whereNotNull('gateway_provider')
        ->whereIn('gateway_provider', ['stripe', 'razorpay', 'paystack', 'flutterwave', 'mercado_pago'])->exists();

    if (! $hasCol) {
        $out[] = 'The migration has not run — the feature is inert. Hit /migrate, then refund again.';
    } elseif (! $toOriginal) {
        $out[] = 'This refund did NOT go back to the original method — the amount is in cash or store';
        $out[] = 'credit, so no gateway call happens. Re-do the refund and pick the STRIPE / CARD method';
        $out[] = 'as the "refund to" method.';
    } elseif (! $hasGatewayPayment) {
        $out[] = 'The sale has NO payment tagged with a gateway provider (gateway_provider is null). It was';
        $out[] = 'not paid through the Stripe gateway flow, so there is no Stripe charge to reverse. Pay a';
        $out[] = 'fresh sale using the Stripe QR tile on the cashier, then refund THAT to the card method.';
    } elseif ($return->gateway_refund_status === 'failed') {
        $out[] = 'The gateway WAS called but Stripe rejected it — see gateway_refund_error above.';
    } elseif ($return->gateway_refund_status === 'succeeded') {
        $out[] = 'Reversal succeeded — the refund should be in your Stripe dashboard.';
    } else {
        $out[] = 'Unexpected state — status is null despite an original-method gateway refund. Share this dump.';
    }

    return response('<pre>'.e(implode("\n", $out)).'</pre>');
})->middleware('dev.route:destructive');

// ---------------------------------------------------------------------------
// Demo baseline
// ---------------------------------------------------------------------------

// Web equivalents of the pos:demo-snapshot / pos:demo-reset artisan commands,
// for hosts without SSH. Both still require demo mode on top of the dev gate.
//
//   /demo-snapshot  — capture the CURRENT database as THE baseline every nightly
//                     reset restores. Hit this ONCE, right after seeding the
//                     demo with the data you want visitors to always start from.
//   /demo-reset     — restore that baseline right now (same thing the 2am cron
//                     does); handy to test the reset or clear junk on demand.
Route::get('/demo-snapshot', function () {
    abort_unless(pos_is_demo(), 404);

    @set_time_limit(0);
    @ini_set('memory_limit', '512M');

    $log = app(\App\Actions\Demo\ResetDemoData::class)->capture();
    $abs = $log->file_path
        ? \Illuminate\Support\Facades\Storage::disk($log->destination ?: 'local')->path($log->file_path)
        : null;

    $lines = [
        'status:    '.$log->status,
        'file_path: '.($log->file_path ?? '(none)'),
        'abs_path:  '.($abs ?? '(none)'),
        'exists:    '.($abs && is_file($abs) ? 'YES' : 'NO'),
        'size:      '.number_format(($log->file_size_bytes ?? 0) / 1024, 1).' KB',
    ];
    if ($log->status !== 'success') {
        $lines[] = 'error:     '.($log->error_message ?? 'unknown');
    } else {
        $lines[] = '';
        $lines[] = 'Baseline captured. The nightly reset (and /demo-reset) will restore this state.';
    }

    return '<pre>'.e(implode("\n", $lines)).'</pre>';
})->middleware('dev.route:destructive');

Route::get('/demo-reset', function () {
    abort_unless(pos_is_demo(), 404);

    @set_time_limit(0);
    @ini_set('memory_limit', '512M');

    $reset    = app(\App\Actions\Demo\ResetDemoData::class);
    $baseline = $reset->baseline();
    if ($baseline === null) {
        return '<pre>No baseline captured yet. Visit /demo-snapshot first.</pre>';
    }

    $abs = \Illuminate\Support\Facades\Storage::disk($baseline->destination ?: 'local')->path($baseline->file_path);
    if (! is_file($abs)) {
        return '<pre>'.e(
            "Baseline file is MISSING on disk:\n  {$abs}\n\n".
            "The backup_logs row exists but the .zip is gone.\n".
            'Fix: visit /demo-snapshot to capture a fresh baseline, then retry /demo-reset.'
        ).'</pre>';
    }

    try {
        $log = $reset();
    } catch (\Throwable $e) {
        return '<pre>'.e('Demo reset FAILED: '.$e->getMessage()).'</pre>';
    }

    return '<pre>'.e($log && $log->status === 'success'
        ? 'Demo reset complete — database restored to baseline.'
        : 'Demo reset FAILED: '.($log?->error_message ?? 'unknown error')
    ).'</pre>';
})->middleware('dev.route:destructive');
