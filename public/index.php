<?php

use Illuminate\Foundation\Application;
use Illuminate\Http\Request;

define('LARAVEL_START', microtime(true));

// Optional SaaS shared hosting. Ordinary Hyper-POS installs have no registration.
$sharedRegistration = __DIR__.'/../storage/app/private/saas-shared-http.json';
if (file_exists($sharedRegistration) || is_link($sharedRegistration)) {
    try {
        if (is_link($sharedRegistration) || filesize($sharedRegistration) > 8192) throw new RuntimeException();
        $sharedHttp = json_decode(file_get_contents($sharedRegistration), true, 16, JSON_THROW_ON_ERROR);
        if (($sharedHttp['version'] ?? null) !== 1 || ($sharedHttp['core_root'] ?? null) !== realpath(__DIR__.'/..')) throw new RuntimeException();
        $authority = preg_replace('/:\d+$/', '', strtolower((string) ($_SERVER['HTTP_HOST'] ?? '')));
        // An unrelated standalone domain keeps the normal POS entry point.
        if ($authority === $sharedHttp['platform_hostname'] || str_ends_with($authority, '.'.$sharedHttp['workspace_domain']) || isset($sharedHttp['workspace_aliases'][$authority])) {
            foreach (['core_root','platform_root','source_root'] as $key) {
                if (!is_string($sharedHttp[$key] ?? null) || realpath($sharedHttp[$key]) !== $sharedHttp[$key] || is_link($sharedHttp[$key])) throw new RuntimeException();
            }
            $entry = $sharedHttp['source_root'].'/bin/shared-workspace-http.php';
            if (!is_file($entry) || is_link($entry)) throw new RuntimeException();
            require $entry;
            return;
        }
    } catch (Throwable $error) {
        $message = gmdate('c').' Shared workspace HTTP failure '.json_encode([
            'stage'=>'core_entry','exception'=>get_class($error),'file'=>basename($error->getFile()),'line'=>$error->getLine(),
        ]);
        error_log($message);
        $diagnostic = __DIR__.'/../storage/logs/saas-shared-http.log';
        if (is_dir(dirname($diagnostic)) && !is_link($diagnostic)) {
            @error_log($message."\n", 3, $diagnostic);
            if (is_file($diagnostic)) @chmod($diagnostic, 0600);
        }
        http_response_code(503);
        header('Cache-Control: no-store');
        return;
    }
}

// Determine if the application is in maintenance mode...
if (file_exists($maintenance = __DIR__.'/../storage/framework/maintenance.php')) {
    require $maintenance;
}

// Register the Composer autoloader...
require __DIR__.'/../vendor/autoload.php';

// Bootstrap Laravel and handle the request...
/** @var Application $app */
$app = require_once __DIR__.'/../bootstrap/app.php';

$app->handleRequest(Request::capture());
