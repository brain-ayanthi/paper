<?php

namespace App\Actions\Licensing;

use App\Models\Company;
use App\Services\Licensing\LicenseClient;
use Illuminate\Support\Facades\Cache;
use Illuminate\Validation\ValidationException;

class DeregisterLicense
{
    public function __construct(private readonly LicenseClient $client) {}

    public function __invoke(): void
    {
        if (pos_is_demo()) {
            throw ValidationException::withMessages(['_action' => __('settings.demo.locked')]);
        }
        $lock = Cache::store('file')->lock('license-operation', 60);
        if (! $lock->get()) {
            throw ValidationException::withMessages(['_action' => __('settings.license.busy')]);
        }
        try {
            $company = Company::current();
            if (! $company) {
                throw ValidationException::withMessages(['_action' => __('installer.license.errors.invalid')]);
            }
            $result = $this->client->deregister(
                (string) config('pos.license.key'),
                (string) config('app.url'),
                (string) config('pos.license.registration_token'),
            );
            if (! $result['ok'] || ($result['body']['status'] ?? null) !== 'deregistered') {
                throw ValidationException::withMessages(['_action' => $result['error']
                    ?? $result['body']['message'] ?? __('installer.license.errors.invalid')]);
            }
            $company->forceFill([
                'license_status' => 'invalid',
                'license_last_checked_at' => now(),
                'license_last_error' => __('installer.license.errors.domain_revoked'),
            ])->save();
            // Retain the token: the API allows it for a subsequent domain move.
            forget_app_license();
        } finally {
            $lock->release();
        }
    }
}
