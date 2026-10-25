<?php

namespace App\Actions\System;

use App\Models\ScheduledTaskRun;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Runs a recurring task once per window, catching up if the cron was asleep
 * when the window opened.
 *
 * Laravel's `dailyAt('02:00')` is tick-based: `schedule:run` must execute
 * during that exact minute or the task is skipped, silently, until tomorrow.
 * Shared hosts routinely pause cron for a nightly maintenance window, so on
 * those installs a 02:00 task never runs — not late, never — while every
 * every-minute task keeps working and the install looks perfectly healthy.
 * That cost one customer a month of backups they believed they had.
 *
 * Here the schedule entry ticks every minute and asks this class whether the
 * current window has already been served. A pause means the task runs late
 * rather than not at all.
 *
 * The window is claimed BEFORE the work starts. A task killed outright — an
 * OOM or a host time-limit, neither of which is a catchable exception — must
 * not be retried every minute for the rest of the day on a host that is
 * already struggling. The half-finished row it leaves behind (`status =
 * 'running'`) is also the only evidence such a death happened, and
 * {@see \App\Actions\Settings\RunScheduledBackup} reports it.
 */
class RunDailyTask
{
    /**
     * Run `$work` if `$periodStartedAt` hasn't been served yet.
     *
     * @return bool whether the work ran
     */
    public function __invoke(string $key, CarbonInterface $periodStartedAt, callable $work): bool
    {
        if (! $this->claim($key, $periodStartedAt)) {
            return false;
        }

        try {
            $work();
        } catch (\Throwable $e) {
            $this->record($key, ScheduledTaskRun::STATUS_FAILED, $e->getMessage());

            // Rethrow: the scheduler reports it, and per-task hooks (e.g.
            // `backup.failed`) still get to notify the admin.
            throw $e;
        }

        $this->record($key, ScheduledTaskRun::STATUS_OK);

        return true;
    }

    /** Has this window already been claimed? */
    public function due(string $key, CarbonInterface $periodStartedAt): bool
    {
        $last = $this->last($key);

        return $last === null || $last->period_started_at->lessThan($periodStartedAt);
    }

    public function last(string $key): ?ScheduledTaskRun
    {
        return ScheduledTaskRun::query()->where('task_key', $key)->first();
    }

    /**
     * Take ownership of a window. Written as a conditional UPDATE so two
     * overlapping runs can't both claim it — `withoutOverlapping()` already
     * makes that unlikely, but a mutex is a cache lock and this is the
     * durable guard.
     */
    public function claim(string $key, CarbonInterface $periodStartedAt): bool
    {
        $now = now();

        $row = [
            'period_started_at' => $periodStartedAt,
            'ran_at'            => $now,
            'status'            => ScheduledTaskRun::STATUS_RUNNING,
            'message'           => null,
            'updated_at'        => $now,
        ];

        $claimed = DB::table('scheduled_task_runs')
            ->where('task_key', $key)
            ->where('period_started_at', '<', $periodStartedAt)
            ->update($row);

        if ($claimed > 0) {
            return true;
        }

        // Either someone already served this window, or the task has never run.
        if (DB::table('scheduled_task_runs')->where('task_key', $key)->exists()) {
            return false;
        }

        try {
            DB::table('scheduled_task_runs')->insert($row + [
                'task_key'   => $key,
                'created_at' => $now,
            ]);
        } catch (\Throwable) {
            return false; // lost the race on the unique key
        }

        return true;
    }

    public function record(string $key, string $status, ?string $message = null): void
    {
        DB::table('scheduled_task_runs')
            ->where('task_key', $key)
            ->update([
                'status'     => $status,
                'message'    => $message === null ? null : Str::limit($message, 480),
                'updated_at' => now(),
            ]);
    }
}
