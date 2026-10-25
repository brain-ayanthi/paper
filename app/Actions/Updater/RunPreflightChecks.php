<?php

namespace App\Actions\Updater;

use App\Services\Updater\SignatureVerifier;
use Illuminate\Support\Facades\DB;

/**
 * Pre-flight gate for an install (docs/features/updater-hooks.md §3.1 step 1).
 * Pure inspection — changes nothing. Returns one row per check so the install
 * wizard can render a tick/cross list and block the button when a *required*
 * check fails.
 *
 * Plugins can append their own via the `updater.preflight_checks` filter.
 */
class RunPreflightChecks
{
    /**
     * @param  array<string,mixed>  $release
     * @param  bool                 $manual   true for a manually-uploaded package,
     *                                        which is not signature-verified
     *                                        (see {@see InstallUpdate}) and so is
     *                                        not gated on verification being set up.
     * @return list<array{key:string,label:string,passed:bool,message:?string,required:bool}>
     */
    public function __invoke(array $release, bool $manual = false): array
    {
        $checks = [];

        // Package verification — feed installs only.
        //
        // Without this the install would pass every check, take a full backup,
        // download tens of megabytes and only THEN abort on a missing signing
        // key. Worse, it is a vendor-side misconfiguration: if the shipped
        // config/pos.php has no public key, EVERY install in the field fails
        // its next feed update identically. Surfacing it as a pre-flight row
        // makes it visible and cheap instead of late and confusing.
        if (! $manual) {
            $checks[] = $this->result(
                'verification',
                __('updates.preflight.verification'),
                $this->canVerifySignatures(),
                $this->verificationMessage(),
            );
        }

        // PHP version.
        $minPhp = (string) ($release['min_php'] ?? '');
        $checks[] = $this->result(
            'php',
            __('updates.preflight.php', ['min' => $minPhp ?: '—']),
            $minPhp === '' || version_compare(PHP_VERSION, $minPhp, '>='),
            __('updates.preflight.php_have', ['have' => PHP_VERSION]),
        );

        // MySQL version — only meaningful on MySQL; skipped elsewhere (tests use SQLite).
        $minMysql = (string) ($release['min_mysql'] ?? '');
        if ($minMysql !== '' && DB::connection()->getDriverName() === 'mysql') {
            $have = $this->mysqlVersion();
            $checks[] = $this->result(
                'mysql',
                __('updates.preflight.mysql', ['min' => $minMysql]),
                $have !== null && version_compare($have, $minMysql, '>='),
                $have ? __('updates.preflight.mysql_have', ['have' => $have]) : null,
            );
        }

        // Disk space — need roughly 3× the zip (zip + extracted + backup buffer).
        $needed = (int) ($release['size_bytes'] ?? 0) * 3;
        $free   = @disk_free_space(base_path());
        if ($free === false) {
            $checks[] = $this->result('disk', __('updates.preflight.disk'), true, __('updates.preflight.disk_unknown'), false);
        } else {
            $checks[] = $this->result(
                'disk',
                __('updates.preflight.disk'),
                $needed === 0 || $free >= $needed,
                __('updates.preflight.disk_have', ['have' => $this->mb($free), 'need' => $this->mb($needed)]),
            );
        }

        // Writable paths the update needs to touch.
        foreach ([
            'storage'        => storage_path(),
            'bootstrap_cache' => base_path('bootstrap/cache'),
            'app_root'       => base_path(),
        ] as $key => $path) {
            $checks[] = $this->result(
                'writable_'.$key,
                __('updates.preflight.writable', ['path' => $this->relative($path)]),
                is_writable($path),
            );
        }

        return apply_filters('updater.preflight_checks', $checks, $release);
    }

    /** True only if every required check passed. */
    public function passed(array $checks): bool
    {
        foreach ($checks as $check) {
            if (($check['required'] ?? true) && ! ($check['passed'] ?? false)) {
                return false;
            }
        }

        return true;
    }

    /**
     * @return array{key:string,label:string,passed:bool,message:?string,required:bool}
     */
    private function result(string $key, string $label, bool $passed, ?string $message = null, bool $required = true): array
    {
        return compact('key', 'label', 'passed', 'message', 'required');
    }

    /**
     * Can this install verify a signed package at all? Mirrors the conditions
     * {@see \App\Services\Updater\SignatureVerifier::verifySignature()} throws
     * on, so the pre-flight row and the eventual failure never disagree.
     */
    private function canVerifySignatures(): bool
    {
        if (app(SignatureVerifier::class)->unsignedAllowed()) {
            return true;
        }

        // Checked before the constant below is touched — SODIUM_CRYPTO_SIGN_*
        // does not exist at all on a build without the extension.
        if (! \function_exists('sodium_crypto_sign_verify_detached')) {
            return false;
        }

        $key = base64_decode((string) config('pos.updater.public_key', ''), true);

        return $key !== false && \strlen($key) === SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES;
    }

    /** The specific reason verification is unavailable, so the fix is obvious. */
    private function verificationMessage(): ?string
    {
        if (app(SignatureVerifier::class)->unsignedAllowed()) {
            return __('updates.preflight.verification_unsigned_allowed');
        }

        $raw = (string) config('pos.updater.public_key', '');
        if ($raw === '') {
            return __('updates.errors.no_public_key');
        }

        if (! \function_exists('sodium_crypto_sign_verify_detached')) {
            return __('updates.errors.sodium_unavailable');
        }

        $key = base64_decode($raw, true);
        if ($key === false || \strlen($key) !== SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES) {
            return __('updates.errors.public_key_invalid');
        }

        return __('updates.preflight.verification_ok');
    }

    private function mysqlVersion(): ?string
    {
        try {
            $row = DB::selectOne('select version() as v');
            $raw = $row->v ?? null;

            // "8.0.36-0ubuntu0.22.04.1" → "8.0.36"
            return $raw ? (preg_split('/[^0-9.]/', (string) $raw)[0] ?: null) : null;
        } catch (\Throwable) {
            return null;
        }
    }

    private function mb(int $bytes): string
    {
        return number_format($bytes / 1024 / 1024, 0).' MB';
    }

    private function relative(string $path): string
    {
        $base = base_path();

        return str_starts_with($path, $base) ? (trim(substr($path, strlen($base)), '/\\') ?: '/') : $path;
    }
}
