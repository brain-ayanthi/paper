<?php

namespace App\Http\Controllers;

use App\Models\Company;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schema;
use Symfony\Component\HttpFoundation\Response;

/**
 * URL-cron fallback — `GET /cron/{token}`.
 *
 * Some budget shared hosts offer no shell cron at all, only "fetch this URL on
 * a schedule" (or the customer uses an external pinger like cron-job.org).
 * Without this, those installs can never run a scheduled backup. Hitting this
 * URL every minute is equivalent to the crontab line.
 *
 * Security posture — this endpoint runs real work with no logged-in user, so:
 *
 *  - It does not exist until the operator explicitly generates a token from
 *    Settings → Scheduler. No token → 404, so a default install exposes nothing.
 *  - The token is 48 random chars, compared with `hash_equals` (constant time).
 *  - A wrong token 404s rather than 403s, so probing can't confirm the feature
 *    is even in use.
 *  - Rate-limited by middleware, and additionally throttled below so a flood of
 *    requests can't stack up scheduler runs on a small shared host.
 *
 * The token is a shared secret in a URL, which is why it's opt-in and
 * one-click-revocable — not why it's unsafe. It grants exactly one capability:
 * "run the tasks that were already going to run on a timer".
 */
class CronController extends Controller
{
    /**
     * Minimum gap between two URL-triggered runs. The scheduler's finest
     * granularity is one minute, so anything more frequent is pure load with
     * nothing to show for it.
     */
    private const MIN_INTERVAL_SECONDS = 50;

    public function __invoke(Request $request, string $token): Response
    {
        $company = Company::current();

        $configured = $this->configuredToken($company);

        // No token minted (the default) or a mismatch — both look identical
        // from outside.
        abort_if($configured === null || $configured === '', 404);
        abort_unless(hash_equals($configured, $token), 404);

        // Expired tokens are dead (SEC #101). A URL-borne secret ends up in
        // crontab, in an external pinger's config, and in every access log
        // along the way; giving it an end date bounds how long a leak through
        // any of those is worth anything. Same 404 as a wrong token — an
        // expiry must not be a way to confirm the feature exists. NULL means
        // "never expires", which stays available for the customer whose only
        // scheduling option is an external pinger.
        abort_if($this->tokenExpired($company), 404);

        $last = $company?->scheduler_last_run_at;

        if ($last && now()->diffInSeconds($last, absolute: true) < self::MIN_INTERVAL_SECONDS) {
            return response('Skipped — scheduler already ran less than a minute ago.', 200)
                ->header('Content-Type', 'text/plain');
        }

        // The heartbeat entry inside schedule:run stamps scheduler_last_run_at,
        // so a URL-cron install reports "working" on the settings page exactly
        // like a shell-cron one.
        Artisan::call('schedule:run');

        return response('Scheduler ran at '.now()->toDateTimeString().' UTC', 200)
            ->header('Content-Type', 'text/plain');
    }

    /**
     * Read the token defensively — this route is reachable before `/migrate`
     * has added the column, and a 500 here would be a confusing thing for a
     * customer to find in their cron output.
     */
    private function configuredToken(?Company $company): ?string
    {
        if (! $company) {
            return null;
        }

        try {
            if (! Schema::hasColumn('company', 'scheduler_cron_token')) {
                return null;
            }
        } catch (\Throwable) {
            return null;
        }

        return $company->scheduler_cron_token;
    }

    /**
     * Has the token passed its expiry? Read as defensively as the token
     * itself — the column arrives in a later migration than the token did, so
     * an install mid-update must not 500 in the customer's cron output.
     */
    private function tokenExpired(?Company $company): bool
    {
        if (! $company) {
            return true;
        }

        try {
            if (! Schema::hasColumn('company', 'scheduler_cron_token_expires_at')) {
                return false;
            }
        } catch (\Throwable) {
            return false;
        }

        $expiresAt = $company->scheduler_cron_token_expires_at;

        return $expiresAt !== null && $expiresAt->isPast();
    }
}
