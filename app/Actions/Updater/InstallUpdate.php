<?php

namespace App\Actions\Updater;

use App\Actions\Settings\RunBackup;
use App\Models\Company;
use App\Models\UpdateLog;
use App\Services\Updater\SignatureVerifier;
use Illuminate\Support\Facades\Artisan;
use RuntimeException;

/**
 * Orchestrates a one-click update (docs/features/updater-hooks.md §3):
 *
 *   preflight → pre-update backup → download → verify → maintenance →
 *   extract → replace files → migrate → clear caches → exit maintenance
 *
 * Any failure AFTER file replacement begins triggers a full rollback (code
 * files + DB) from the pre-update backup. Failures before that abort cleanly —
 * nothing was changed yet. Every step is recorded on the UpdateLog.
 *
 * Maintenance mode is skipped under the test runner. `$targetRoot` is
 * injectable so tests apply the update to a sandbox, never the live install.
 */
class InstallUpdate
{
    public function __construct(
        private readonly RunPreflightChecks $preflight,
        private readonly RunBackup $runBackup,
        private readonly DownloadUpdateZip $download,
        private readonly SignatureVerifier $verifier,
        private readonly ExtractUpdateZip $extract,
        private readonly ReplaceApplicationFiles $replace,
        private readonly ExecuteUpdateMigrations $migrations,
        private readonly RollbackUpdate $rollback,
    ) {
    }

    /**
     * @param  array<string,mixed>  $release       the release metadata
     * @param  string|null          $localZipPath  a pre-supplied local zip (manual
     *                                              upload) — skips the feed download
     *                                              and, by product decision, the
     *                                              signature check; null = fetch
     *                                              + verify from the feed as usual.
     */
    public function __invoke(array $release, ?int $userId = null, ?string $targetRoot = null, ?string $localZipPath = null): UpdateLog
    {
        $from    = (string) config('pos.version', '1.0.0');
        $to      = (string) ($release['version'] ?? '');
        $company = Company::current();
        $channel = $company?->update_channel ?: (string) config('pos.updater.channel', 'stable');

        do_action('updater.before_install', $to, $release);

        $log = UpdateLog::create([
            'from_version' => $from,
            'to_version'   => $to,
            'started_at'   => now(),
            'status'       => 'pending',
            'channel'      => $channel,
            'created_by'   => $userId,
        ]);

        $useMaintenance = ! app()->runningUnitTests();
        $steps          = [];
        $backup         = null;
        $rollbackDir    = null;
        $replaced       = false;

        try {
            // 1. Pre-flight — abort before touching anything if the environment is unfit.
            $checks = ($this->preflight)($release, $localZipPath !== null);
            if (! $this->preflight->passed($checks)) {
                throw new RuntimeException(__('updates.errors.preflight_failed'));
            }
            $steps['preflight'] = 'ok';

            // 2. Mandatory pre-update backup — the rollback's safety net.
            $backup = ($this->runBackup)('pre-update', $userId);
            $log->update(['pre_update_backup_id' => $backup->id]);
            $steps['backup'] = $backup->id;

            // 3. Acquire + verify the package before extraction.
            if ($localZipPath !== null) {
                // Manual upload — signature verification is deliberately NOT
                // performed here, by product decision: the admin uploading the
                // file is trusted, and requiring an out-of-band signature would
                // make this path unusable as the recovery route it exists to be
                // (it is the only way to update an install whose feed is
                // unreachable, or that shipped before signing keys existed).
                //
                // The trade-off is real and is accepted knowingly: an admin who
                // is tricked into uploading a malicious zip gets it copied over
                // the live application. Nothing on this path can detect that —
                // the `sha256` in the package metadata is self-referential
                // (whoever supplies the zip supplies the expected hash too, SEC
                // #3 / defect #74), so it is not a control either and is not
                // checked. The feed path remains fully verified.
                //
                // Recorded on the UpdateLog so an install's history always
                // shows which packages arrived unverified.
                $zipPath = $localZipPath;

                $steps['download'] = 'local';
                $steps['verify']   = 'skipped_manual_upload';

                do_action('updater.manual_install_unverified', $to, $release);
            } else {
                [$zipPath, $signature] = ($this->download)($release);
                $this->verifier->verify($zipPath, (string) ($release['sha256'] ?? ''), $signature);
                $steps['download'] = 'ok';
                $steps['verify']   = 'ok';
            }

            // 4. Maintenance mode.
            if ($useMaintenance) {
                Artisan::call('down');
            }
            do_action('updater.pre_update_hooks', $to);

            // 5. Extract.
            $source      = ($this->extract)($zipPath);
            $rollbackDir = dirname($zipPath).'/rollback';
            $steps['extract'] = 'ok';
            $log->update(['status' => 'installing', 'steps_log' => $steps]);

            // Keep the migration service on the same framework generation as
            // the connection classes already loaded by this request. Resolving
            // it after vendor/ is replaced can mix old and new Laravel classes.
            $this->migrations->prepare();

            // 6. Replace files (originals stashed in $rollbackDir for a real rollback).
            $replaced         = true;
            $steps['replace'] = ($this->replace)($source, $targetRoot, $rollbackDir);

            // 7. Migrate forward.
            ($this->migrations)();
            $steps['migrate'] = 'ok';

            // 8. Clear caches.
            $this->clearCaches();
            $steps['caches'] = 'ok';

            do_action('updater.post_update_hooks', $to);

            // 9. Back online.
            if ($useMaintenance) {
                Artisan::call('up');
            }

            // The banner has served its purpose.
            $company?->update(['update_available_version' => null, 'update_available_release' => null]);
            forget_app_updates();

            $log->update(['status' => 'success', 'finished_at' => now(), 'steps_log' => $steps]);
            do_action('updater.after_install', $to, $log);

            return $log->fresh();
        } catch (\Throwable $e) {
            $steps['error'] = $e->getMessage();
            $rolledBack     = false;

            // Roll back only if we'd begun changing the install.
            if ($replaced && $backup) {
                try {
                    ($this->rollback)($backup, $rollbackDir, $targetRoot);
                    $rolledBack = true;
                } catch (\Throwable $re) {
                    $steps['rollback_error'] = $re->getMessage();
                }
            }

            if ($useMaintenance) {
                try {
                    Artisan::call('up');
                } catch (\Throwable) {
                }
            }

            $log->update([
                'status'        => $rolledBack ? 'rolled_back' : 'failed',
                'finished_at'   => now(),
                'error_message' => $e->getMessage(),
                'steps_log'     => $steps,
            ]);
            do_action($rolledBack ? 'updater.rolled_back' : 'updater.install_failed', $to, $log, $e);

            throw $e;
        }
    }

    private function clearCaches(): void
    {
        foreach (['cache:clear', 'config:clear', 'view:clear', 'route:clear'] as $command) {
            try {
                Artisan::call($command);
            } catch (\Throwable) {
            }
        }
    }
}
