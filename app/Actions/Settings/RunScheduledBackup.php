<?php

namespace App\Actions\Settings;

use App\Actions\System\RunDailyTask;
use App\Models\BackupLog;
use App\Models\ScheduledTaskRun;
use App\Support\DailyWindow;
use Carbon\CarbonImmutable;

/**
 * The automatic backup — everything the scheduler entry used to do inline, plus
 * the two things it got wrong.
 *
 * Previously this was three `Schedule::call()` entries (`dailyAt('02:00')`,
 * `weeklyOn(0, '02:00')`, `monthlyOn(1, '02:00')`) filtered by a `when()` gate.
 * That shape has a failure mode with no symptom: if `schedule:run` doesn't
 * execute during the 02:00 minute, nothing runs and nothing is recorded — and
 * 02:00–05:00 is exactly when shared hosts pause cron for their own maintenance.
 * One customer went a month with automatic backups that had never once run,
 * while the settings page cheerfully showed a recent backup date (the updater's
 * pre-update snapshot, which shares the same column).
 *
 * Now the window is a range: the entry ticks every minute and this class serves
 * the current window if it hasn't been served yet. A cron gap makes the backup
 * late instead of absent.
 */
class RunScheduledBackup
{
    /** Identifier in `scheduled_task_runs`. */
    public const TASK_KEY = 'backup';

    /**
     * When the daily/weekly/monthly window opens, in the STORE's timezone.
     *
     * Not yet customer-configurable — the settings screen offers frequency
     * only ("Daily — 2 AM"). Making it a field is the natural follow-up now
     * that a late window is survivable.
     */
    public const TIME = '02:00';

    public function __construct(
        private readonly RunBackup $runBackup,
        private readonly PruneBackups $prune,
        private readonly EmailBackupCopy $emailCopy,
        private readonly RunDailyTask $task,
    ) {
    }

    /**
     * The start of the current window, or null when automatic backups are off
     * (or the stored frequency is something we don't recognise).
     */
    public function periodStartedAt(): ?CarbonImmutable
    {
        $settings = app_backup();

        if (! $settings['enabled']) {
            return null;
        }

        return match ($settings['frequency']) {
            'daily'   => DailyWindow::daily(self::TIME),
            'weekly'  => DailyWindow::weekly(0, self::TIME),
            'monthly' => DailyWindow::monthly(1, self::TIME),
            default   => null,
        };
    }

    /** Cheap enough to ask every minute: two indexed reads. */
    public function isDue(): bool
    {
        $period = $this->periodStartedAt();

        return $period !== null && $this->task->due(self::TASK_KEY, $period);
    }

    /** @return bool whether a backup was taken */
    public function __invoke(): bool
    {
        $period = $this->periodStartedAt();

        if ($period === null) {
            return false;
        }

        $this->reportSilentDeath();

        return ($this->task)(self::TASK_KEY, $period, function () {
            // Prune FIRST. Retention used to be applied after a successful
            // backup, which deadlocks on a full disk: the write fails, so the
            // prune that would have freed the space never runs, so every
            // subsequent night fails the same way. Freeing first also means the
            // archive we're about to write has somewhere to go.
            ($this->prune)();

            $log = ($this->runBackup)('scheduled');

            ($this->emailCopy)($log);
        });
    }

    /**
     * Warn when the previous window's backup vanished without a trace.
     *
     * A caught failure already notifies through the `backup.failed` hook. What
     * nothing covered is the process being killed outright — an OOM or a host
     * time limit raises no exception, so the BackupLog stays at 'running' and
     * the admin hears nothing at all. Silence is the one failure mode a backup
     * feature cannot afford, so a claimed-but-never-finished window is reported
     * on the next one.
     */
    private function reportSilentDeath(): void
    {
        $last = $this->task->last(self::TASK_KEY);

        if ($last === null || $last->status !== ScheduledTaskRun::STATUS_RUNNING) {
            return;
        }

        if (admins_have_unread_notification('backup_overdue')) {
            return;
        }

        // Leave the interrupted BackupLog row consistent with what happened,
        // so the backup list doesn't show a run that's been "in progress" for
        // a day.
        BackupLog::query()
            ->where('type', 'scheduled')
            ->where('status', 'running')
            ->where('started_at', '>=', $last->ran_at)
            ->update([
                'status'        => 'failed',
                'finished_at'   => now(),
                'error_message' => __('notifications.backup_overdue.interrupted'),
            ]);

        notify_admins(
            'backup_overdue',
            __('notifications.backup_overdue.title'),
            __('notifications.backup_overdue.message', ['when' => format_datetime($last->ran_at)]),
            'database',
            route('admin.settings.backup.edit'),
            'backup.run',
        );
    }
}
