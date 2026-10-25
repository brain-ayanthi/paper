<?php

namespace App\Actions\Licensing;

use App\Actions\Installer\PersistLicense;
use App\Actions\Installer\ValidateLicense;
use App\Actions\Installer\WriteEnvFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Validation\ValidationException;

class ActivateLicense
{
    public function __construct(
        private readonly ValidateLicense $validate,
        private readonly WriteEnvFile $writeEnv,
        private readonly PersistLicense $persist,
    ) {}

    public function __invoke(string $key): void
    {
        if (pos_is_demo()) {
            throw ValidationException::withMessages(['_action' => __('settings.demo.locked')]);
        }
        $lock = Cache::store('file')->lock('license-operation', 60);
        if (! $lock->get()) {
            throw ValidationException::withMessages(['_action' => __('settings.license.busy')]);
        }
        try {
            $key = trim($key);
            $result = ($this->validate)($key, (string) config('app.url'));
            if (! $result['ok']) {
                throw ValidationException::withMessages(['license_key' => $result['error']]);
            }
            ($this->writeEnv)([
                'LICENSE_KEY' => $key,
                'LICENSE_FINGERPRINT' => $result['fingerprint'],
                'LICENSE_REGISTRATION_TOKEN' => $result['response']['token'] ?? '',
            ]);
            // Discard stale cached environment values after an explicit activation.
            if (app()->configurationIsCached()) {
                app('files')->delete(app()->getCachedConfigPath());
            }
            config([
                'pos.license.key' => $key,
                'pos.license.fingerprint' => $result['fingerprint'],
                'pos.license.registration_token' => $result['response']['token'] ?? '',
            ]);
            ($this->persist)($result['response']);
            Cache::store('file')->forget('license-check:'.hash('sha256', $key.'|'.config('app.url')));
        } finally {
            $lock->release();
        }
    }
}
