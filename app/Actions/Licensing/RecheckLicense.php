<?php

namespace App\Actions\Licensing;

use App\Actions\Installer\WriteEnvFile;
use App\Models\Company;
use App\Services\Licensing\LicenseClient;
use Illuminate\Support\Facades\Cache;

/** Binding checks and missing-token recovery shared by middleware and scheduler. */
class RecheckLicense
{
    public function __construct(private readonly LicenseClient $client, private readonly WriteEnvFile $writeEnv) {}

    public function __invoke(bool $force = false): array
    {
        if (config('pos.license.dev_bypass')) {
            return ['ok' => true, 'skipped' => true, 'reason' => 'dev_bypass'];
        }
        if (! config('pos.license.required') || pos_is_demo()) {
            return ['ok' => true, 'skipped' => true, 'reason' => 'disabled'];
        }
        if (! config('pos.license.recheck')) {
            return ['ok' => true, 'skipped' => true, 'reason' => 'recheck_disabled'];
        }

        // Serialize checks with de-registration so a stale active response cannot
        // overwrite a successful local revocation.
        $lock = Cache::store('file')->lock('license-operation', 60);
        if (! $lock->get()) {
            return ['ok' => true, 'skipped' => true, 'reason' => 'busy'];
        }
        try {
            $company = Company::current();
            if ($company === null) {
                return ['ok' => false, 'skipped' => true, 'reason' => 'no_company'];
            }
            $key = trim((string) config('pos.license.key', ''));
            $domain = (string) config('app.url');
            // Separate cadence per installation URL. A copied database must not
            // borrow the previous domain's recent verification timestamp.
            $cacheKey = 'license-check:'.hash('sha256', $key.'|'.$domain);
            if (! $force && Cache::store('file')->has($cacheKey)) {
                return ['ok' => true, 'skipped' => true, 'reason' => 'fresh', 'status' => $company->license_status];
            }
            if ($key === '' || strtoupper($key) === 'OFFLINE') {
                $result = ['ok' => true, 'body' => ['status' => 'invalid',
                    'message' => __('installer.license.errors.required')]];
            } else {
                $result = $this->client->inspect($key, $domain);
            }
            if (! $result['ok']) {
                $company->forceFill([
                    'license_last_checked_at' => now(),
                    'license_last_error' => $result['error'],
                ])->save();
                Cache::store('file')->put($cacheKey, true, 60);
                forget_app_license();

                return ['ok' => false, 'error' => $result['error'], 'status' => $company->license_status];
            }
            $status = $result['body']['status'];
            $recoveryError = null;
            if ($status === 'valid' && trim((string) config('pos.license.registration_token', '')) === '') {
                // Never reach the binding endpoint unless a fresh read-only check
                // has confirmed this exact domain is active.
                $recovery = $this->client->check(['license_key' => $key, 'install_url' => $domain]);
                if (($recovery['ok'] ?? false) && ($recovery['body']['status'] ?? null) === 'invalid') {
                    $status = 'invalid';
                    $result = $recovery;
                } elseif (($recovery['ok'] ?? false)
                    && ($recovery['body']['registration_status'] ?? null) === 'already_registered') {
                    try {
                        ($this->writeEnv)(['LICENSE_REGISTRATION_TOKEN' => $recovery['body']['token']]);
                        if (app()->configurationIsCached() && ! app('files')->delete(app()->getCachedConfigPath())) {
                            throw new \RuntimeException('License configuration cache could not be cleared.');
                        }
                        config(['pos.license.registration_token' => $recovery['body']['token']]);
                    } catch (\Throwable) {
                        $recoveryError = __('installer.license.errors.token_save_failed');
                    }
                } else {
                    $recoveryError = $recovery['error'] ?? __('installer.license.errors.bad_response');
                    // A new binding was not the requested recovery outcome.
                    if (($recovery['body']['registration_status'] ?? null) === 'registered') {
                        $status = 'invalid';
                        $result = ['body' => ['message' => __('installer.license.errors.domain_revoked')]];
                    }
                }
            }
            // Inspect has no support/type fields. Preserve existing purchase metadata.
            $company->forceFill([
                'license_status' => $status,
                'license_last_checked_at' => now(),
                'license_last_error' => $status === 'valid' ? $recoveryError : $result['body']['message'],
            ])->save();
            Cache::store('file')->put($cacheKey, true, $recoveryError ? 60 : max(1, (int) config('pos.license.recheck_minutes', 15)) * 60);
            forget_app_license();

            return ['ok' => true, 'status' => $status];
        } finally {
            $lock->release();
        }
    }
}
