<?php

namespace App\Actions\Installer;

use App\Models\Company;
use Illuminate\Support\Carbon;

/**
 * Writes a validated license response into the company row.
 *
 * The license is validated at install Step 3 (before the DB exists) and the
 * response is stashed in the installer state file; this runs at completion —
 * once the company row exists (created at Step 5) — to make the details
 * queryable by the app (admin License page, support window, re-check). It's
 * also reused by the 30-day re-check to refresh the cached result.
 *
 * No-ops safely if there's no company yet or no license payload to write.
 *
 * @phpstan-param array<string,mixed> $license
 */
class PersistLicense
{
    /** @param array<string,mixed> $license  A validated license server response. */
    public function __invoke(array $license): void
    {
        if (empty($license)) {
            return;
        }

        $company = Company::current();
        if ($company === null) {
            return;
        }

        $company->forceFill([
            'license_status'          => $license['status'] ?? 'unverified',
            'license_type'            => $license['license_type'] ?? null,
            'license_buyer_name'      => $license['buyer_name'] ?? null,
            'license_buyer_email'     => $license['buyer_email'] ?? null,
            'license_expires_at'      => $this->date($license['expires_at'] ?? null),
            'license_support_until'   => $this->date($license['support_until'] ?? null),
            'license_last_checked_at' => now(),
            'license_last_error'      => null,
        ])->save();

        forget_app_license();
    }

    private function date(mixed $value): ?Carbon
    {
        if (empty($value)) {
            return null;
        }

        try {
            return Carbon::parse($value);
        } catch (\Throwable) {
            return null;
        }
    }
}
