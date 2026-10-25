<?php

namespace App\Support\Installer;

use PDO;

/** Read-only, web-PHP checks for an already prepared fresh SaaS package. */
final class SharedHostingFailureDiagnostic
{
    public function code(#[\SensitiveParameter] array $input): ?string
    {
        if (! app()->bound('saas.fresh.prepared_source')) return null;
        $prepared = app('saas.fresh.prepared_source');
        if (! is_array($prepared)) return null;

        $rootClass = \HyperPos\Saas\Setup\SharedPlatformRoot::class;
        $activationClass = \HyperPos\Saas\Setup\ActivationConfiguration::class;
        $readinessClass = \HyperPos\Saas\Setup\SharedPlatformDatabaseReadiness::class;
        $approvalClass = \HyperPos\Saas\Packaging\FreshInstallerPackageApproval::class;
        $rehearsalClass = \HyperPos\Saas\Packaging\FreshInstallerRehearsalAuthorization::class;
        if (! class_exists($rootClass) || ! class_exists($activationClass)
            || ! class_exists($readinessClass) || ! class_exists($approvalClass)
            || ! class_exists($rehearsalClass)) return null;

        try {
            $source = (string) ($prepared['source_root']
                ?? (($prepared['source_directory'] ?? '').'/source'));
            if ($rootClass::resolve($input['platform_root'] ?? null,
                dirname(public_path()), $source) === null) {
                return $this->rootFailure($input['platform_root'] ?? null,
                    dirname(public_path()), $source);
            }
        } catch (\Throwable) {
            return 'platform_root';
        }

        try {
            $installerDomain = parse_url((string) config('app.url'), PHP_URL_HOST);
            $activation = (new $activationClass)->normalizeFresh([
                'platform_domain' => (string) ($input['platform_domain'] ?? ''),
                'workspace_domain' => (string) ($input['workspace_domain'] ?? ''),
                'platform_database' => (string) ($input['platform_database'] ?? ''),
            ], (string) $installerDomain);
        } catch (\Throwable) {
            return 'domains';
        }

        try {
            $host = trim((string) ($input['database_host'] ?? ''));
            $port = (int) ($input['database_port'] ?? 0);
            $username = trim((string) ($input['database_username'] ?? ''));
            $password = (string) ($input['database_password'] ?? '');
            if ($host === '' || $port < 1 || $port > 65535 || $username === '') {
                return 'database_details';
            }
            $pdo = new PDO(
                'mysql:host='.$host.';port='.$port.';dbname='.$activation['platform_database'].';charset=utf8mb4',
                $username, $password,
                [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_TIMEOUT => 5],
            );
            $reserved = 'hps_installer_reserved_'.substr(hash('sha256', (string) $installerDomain), 0, 16);
            if (! (new $readinessClass($pdo, $activation['platform_database'], $reserved))->inspect()) {
                return 'database_connection';
            }
        } catch (\Throwable) {
            return 'database_connection';
        } finally {
            unset($password, $pdo);
        }

        try {
            $rehearsal = $rehearsalClass::fromCoreRoot(base_path());
            if ((new $approvalClass(
                (string) ($prepared['delivery_archive'] ?? ''),
                (string) ($prepared['trusted_key_sha256'] ?? ''),
                (string) config('pos.version'), null, $rehearsal,
            ))->approved() === null) return 'package_approval';
        } catch (\Throwable) {
            return 'package_approval';
        }

        return 'runtime_preparation';
    }

    private function rootFailure(mixed $value, string $coreRoot, string $sourceRoot): string
    {
        if (! is_string($value) || $value === '' || strlen($value) > 1024
            || preg_match('/[\x00-\x1F\x7F]/', $value)) return 'platform_root_invalid';
        $path = str_replace('\\', '/', $value);
        if (! preg_match('~\A(?:/|[A-Za-z]:/)~', $path)
            || in_array('.', explode('/', $path), true)
            || in_array('..', explode('/', $path), true)) return 'platform_root_invalid';
        $path = rtrim($path, '/');
        if ($path === '' || preg_match('~\A[A-Za-z]:\z~', $path)) {
            return 'platform_root_invalid';
        }
        for ($cursor = $path; ; $cursor = dirname($cursor)) {
            if (is_link($cursor)) return 'platform_root_symlink';
            if (dirname($cursor) === $cursor) break;
        }

        $parent = realpath(dirname($path));
        if ($parent === false || ! is_dir($parent)) return 'platform_root_parent';
        if (PHP_OS_FAMILY !== 'Windows' && (fileperms($parent) & 0002) !== 0) {
            return 'platform_root_world_writable_parent';
        }
        if (! file_exists($path) && ! is_writable($parent)) return 'platform_root_parent';
        if (file_exists($path)) {
            if (! is_dir($path) || ! is_writable($path)) return 'platform_root_unwritable';
            if (PHP_OS_FAMILY !== 'Windows' && (fileperms($path) & 0002) !== 0) {
                return 'platform_root_world_writable';
            }
        }

        $canonical = file_exists($path) ? realpath($path)
            : rtrim($parent, '/\\').DIRECTORY_SEPARATOR.basename($path);
        if ($canonical === false) return 'platform_root_invalid';
        $canonical = rtrim(str_replace('\\', '/', $canonical), '/');
        foreach ([$coreRoot, $sourceRoot] as $excluded) {
            $real = realpath($excluded);
            if ($real === false) return 'platform_root_source_unavailable';
            $real = rtrim(str_replace('\\', '/', $real), '/');
            $candidate = PHP_OS_FAMILY === 'Windows' ? strtolower($canonical) : $canonical;
            if (PHP_OS_FAMILY === 'Windows') $real = strtolower($real);
            if ($candidate === $real || str_starts_with($candidate, $real.'/')
                || str_starts_with($real, $candidate.'/')) return 'platform_root_overlap';
        }

        return 'platform_root';
    }
}
