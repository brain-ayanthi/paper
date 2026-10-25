<?php

namespace App\Actions\System;

use App\Models\Company;
use Carbon\CarbonInterface;

/**
 * Answers one question: is this install's cron job actually running?
 *
 * Everything scheduled — auto backups, scheduled reports, low-stock alerts,
 * update checks, daily metrics — depends on the customer having added
 * `schedule:run` to their server's crontab. Nothing in the app can *make* that
 * happen, so the next best thing is to know, and say so loudly.
 *
 * The whole diagnosis comes from one timestamp: `company.scheduler_last_run_at`,
 * stamped every minute by the `pos:heartbeat` schedule entry (see
 * {@see RecordSchedulerHeartbeat} and routes/console.php). If it's null the cron
 * was never installed; if it's old the cron stopped. There is no way to fake
 * this from inside a web request, which is exactly the point.
 *
 * Also builds the copy-pasteable cron lines for the customer's actual install
 * path, since "add a cron job" is useless advice without the exact string.
 */
class ResolveSchedulerStatus
{
    /**
     * Fresher than this and the cron is firing at the intended cadence.
     * The heartbeat runs every minute, so a healthy install is normally a
     * few seconds old; 5 minutes is slack for a slow/loaded server.
     */
    public const OK_SECONDS = 300;

    /**
     * Between OK_SECONDS and this, the cron is alive but running less often
     * than every minute. Plenty of budget shared hosts cap cron to once every
     * 5 or 15 minutes, which still works fine for daily backups — so this is
     * a warning, not a failure. Past this, treat it as stopped.
     */
    public const DELAYED_SECONDS = 3600;

    /**
     * @return array{
     *     state: string,
     *     last_run_at: \Illuminate\Support\Carbon|null,
     *     seconds_since: int|null,
     *     human: string|null,
     *     token: string|null,
     *     url: string|null,
     *     token_expires_at: \Illuminate\Support\Carbon|null,
     *     token_expired: bool,
     *     base_path: string,
     *     php_binary: string,
     *     commands: array<string,string>
     * }
     */
    public function __invoke(?Company $company = null): array
    {
        $company ??= Company::current();
        $lastRun = $company?->schedulerLastRunAtUtc();

        $seconds = $lastRun ? max(0, now('UTC')->diffInSeconds($lastRun, absolute: true)) : null;
        $token   = $company?->scheduler_cron_token;

        // Token lifetime (SEC #101). Surfaced so the page can show when the
        // URL stops working — and shout when it already has, since an expired
        // token 404s exactly like a wrong one and a silently-dead cron URL is
        // the worst failure this feature has.
        $expiresAt = $company?->scheduler_cron_token_expires_at;

        return [
            'state'            => $this->state($seconds),
            'last_run_at'      => $lastRun,
            'seconds_since'    => $seconds,
            'human'            => $lastRun?->diffForHumans(syntax: CarbonInterface::DIFF_RELATIVE_TO_NOW),
            'token'            => $token,
            'url'              => $token ? url('/cron/'.$token) : null,
            'token_expires_at' => $token ? $expiresAt : null,
            'token_expired'    => (bool) ($token && $expiresAt && $expiresAt->isPast()),
            'base_path'        => base_path(),
            'php_binary'       => $this->phpBinary(),
            'commands'         => $this->commands($token),
        ];
    }

    /**
     * never  — no heartbeat ever recorded; the cron job was never set up.
     * ok     — firing at roughly the intended once-a-minute cadence.
     * delayed— alive, but slower than every minute (host-capped cron).
     * stale  — it ran at some point and has since stopped.
     */
    private function state(?int $seconds): string
    {
        return match (true) {
            $seconds === null              => 'never',
            $seconds <= self::OK_SECONDS   => 'ok',
            $seconds <= self::DELAYED_SECONDS => 'delayed',
            default                        => 'stale',
        };
    }

    /**
     * Best guess at the PHP CLI binary.
     *
     * `PHP_BINARY` is exactly right under CLI but points at php-fpm/httpd when
     * we're serving a web request — which is when this page renders. `PHP_BINDIR`
     * is the bin directory of the running PHP build, so `PHP_BINDIR/php` is the
     * matching CLI binary on nearly every host. It's offered as an *alternate*
     * line on the settings page precisely because it's a guess: plain `php`
     * works on most hosts, and this is the fallback when it doesn't.
     */
    private function phpBinary(): string
    {
        if (PHP_SAPI === 'cli' && PHP_BINARY !== '') {
            return PHP_BINARY;
        }

        $guess = rtrim(PHP_BINDIR, '/\\').DIRECTORY_SEPARATOR.'php';

        // `false` means we looked and it genuinely isn't there. `null` means we
        // weren't allowed to look — offer the guess anyway: it's the best answer
        // available, and the page presents this as an alternate command to try,
        // not as a verified fact.
        return $this->fileExists($guess) === false ? 'php' : $guess;
    }

    /**
     * `is_file()` that survives `open_basedir`.
     *
     * Shared hosts — precisely the audience for this whole feature — routinely
     * confine PHP to the docroot, and the PHP binary never lives inside it.
     * Statting it there raises a warning that Laravel promotes to an
     * ErrorException, which took out both this page and the System Health row
     * on a real customer install.
     *
     * @return bool|null null when the path is unknowable from here.
     */
    private function fileExists(string $path): ?bool
    {
        if (! $this->withinOpenBasedir($path)) {
            return null;
        }

        try {
            return @is_file($path);
        } catch (\Throwable) {
            return null;
        }
    }

    /** True when `open_basedir` is unset, or `$path` sits under one of its roots. */
    private function withinOpenBasedir(string $path): bool
    {
        $basedir = trim((string) ini_get('open_basedir'));

        if ($basedir === '' || $basedir === 'none') {
            return true;
        }

        $needle = str_replace('\\', '/', $path);

        foreach (explode(PATH_SEPARATOR, $basedir) as $root) {
            $root = rtrim(str_replace('\\', '/', trim($root)), '/');
            if ($root !== '' && str_starts_with($needle, $root.'/')) {
                return true;
            }
        }

        return false;
    }

    /**
     * The cron lines, pre-filled with this install's real paths.
     *
     * `standard` is what to try first. `php_path` is for cPanel/Plesk hosts
     * where the cron shell has no `php` on its PATH (the single most common
     * reason a correctly-added cron job silently never runs). `url` is for
     * hosts that don't offer shell cron at all.
     *
     * @return array<string,string>
     */
    private function commands(?string $token): array
    {
        $path    = base_path();
        $php     = $this->phpBinary();
        $artisan = rtrim($path, '/\\').DIRECTORY_SEPARATOR.'artisan';

        $commands = [
            'standard' => sprintf('* * * * * cd %s && php artisan schedule:run >> /dev/null 2>&1', $path),
            'php_path' => sprintf('* * * * * %s %s schedule:run >> /dev/null 2>&1', $php, $artisan),
        ];

        if ($token) {
            $commands['url']     = url('/cron/'.$token);
            $commands['url_wget'] = sprintf('* * * * * wget -q -O /dev/null %s', url('/cron/'.$token));
        }

        return $commands;
    }
}
