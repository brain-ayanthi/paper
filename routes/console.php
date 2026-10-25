<?php

use App\Actions\Commerce\DrainCommerceWork;
use App\Actions\Demo\ResetDemoData;
use App\Actions\Inventory\CheckLowStock;
use App\Actions\Licensing\RecheckLicense;
use App\Actions\Sales\RetryFailedGatewayReversals;
use App\Actions\Settings\RunScheduledBackup;
use App\Actions\System\RecordSchedulerHeartbeat;
use App\Actions\System\RunDailyTask;
use App\Actions\Updater\AutoInstallIfEligible;
use App\Actions\Updater\CheckForUpdate;
use App\Models\ScheduledTaskRun;
use App\Support\DailyWindow;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

/* ─────────────────────── Scheduler heartbeat ────────────────
 * MUST stay first and must never be gated by a `when()`.
 *
 * Everything below only runs if the customer added the `schedule:run` cron
 * job on their server — and nothing else in the app can tell whether they
 * did. This one-column write every minute is that signal: Settings →
 * Scheduler and System Health read its age to say "cron is working" /
 * "cron was never set up" / "cron stopped".
 *
 * Without it, an install with no cron looks perfectly healthy while auto
 * backups, scheduled reports and low-stock alerts silently never fire.
 */
Schedule::call(fn () => app(RecordSchedulerHeartbeat::class)())
    ->everyMinute()
    ->name('pos:heartbeat');
// Deliberately NOT ->withoutOverlapping(): that takes a cache lock, and a lock
// left behind by a killed process would suppress the heartbeat for hours — so
// the app would report "your cron stopped" on an install whose cron is fine.
// A single-column UPDATE has nothing meaningful to overlap with anyway, and
// this signal has to be trustworthy above all else.

// Drain provider-neutral commerce inbox/outbox rows without requiring a
// separate queue worker. Each processor atomically claims rows, retries with
// exponential backoff, and recovers claims abandoned by a killed cron process.
// Run inside the scheduler process: shared hosts may block detached PHP
// processes even though the heartbeat above succeeds (including URL cron).
$commerceMaxSeconds = max(1, (int) config('commerce.processing.scheduler_max_seconds', 20));
Schedule::call(fn () => app(DrainCommerceWork::class)->run($commerceMaxSeconds))
    ->everyMinute()
    ->name('pos:commerce-work')
    ->withoutOverlapping(5);

/* ───────────── Once-a-day work: due-based, not on-the-dot ──────
 * Everything below that used to say `dailyAt('06:00')` now ticks
 * every minute and asks whether the current window has already
 * been served (see {@see RunDailyTask}).
 *
 * The reason is a failure mode with no symptom. `dailyAt()` fires
 * only if `schedule:run` executes during that exact minute — so a
 * host that pauses cron between 02:00 and 05:00 for its own nightly
 * maintenance (routine on shared hosting) skips every task in that
 * range forever, silently, while the every-minute tasks keep
 * working and the install looks healthy. A real customer lost a
 * month of automatic backups to precisely this.
 *
 * Ticking every minute costs one indexed read per task per minute
 * and buys catch-up: a cron gap now makes a task late, never
 * absent. Windows resolve in the STORE's timezone, not UTC — see
 * {@see DailyWindow}.
 */
$window = fn (string $time) => DailyWindow::daily($time);

/** Register a closure that must run once a day, at or after `$time`. */
$daily = function (string $key, string $time, callable $work) use ($window) {
    Schedule::call(fn () => app(RunDailyTask::class)($key, $window($time), $work))
        ->everyMinute()
        ->when(fn () => app(RunDailyTask::class)->due($key, $window($time)))
        ->name('pos:'.$key)
        ->withoutOverlapping(60);
};

/**
 * Same, for an artisan command. Kept as `Schedule::command()` so the work
 * still runs in its own process with its own memory limit — the window is
 * claimed in `before()` and settled in the parent, which is where the
 * scheduler's callbacks run.
 */
$dailyCommand = function (string $key, string $time, string $command) use ($window) {
    Schedule::command($command)
        ->everyMinute()
        ->when(fn () => app(RunDailyTask::class)->due($key, $window($time)))
        ->before(fn () => app(RunDailyTask::class)->claim($key, $window($time)))
        ->onSuccess(fn () => app(RunDailyTask::class)->record($key, ScheduledTaskRun::STATUS_OK))
        ->onFailure(fn () => app(RunDailyTask::class)->record($key, ScheduledTaskRun::STATUS_FAILED))
        ->name('pos:'.$key)
        ->withoutOverlapping(60);
};

/* ───────────────────────── Backups ─────────────────────────
 * One entry for all three cadences — {@see RunScheduledBackup}
 * owns the frequency maths, the catch-up check, retention and the
 * emailed copy. `withoutOverlapping(60)` caps the mutex at an hour
 * so a killed backup can't lock the next one out: the default is
 * 1440 minutes, which on a daily job is a lock as long as the
 * interval it protects.
 */
Schedule::call(fn () => app(RunScheduledBackup::class)())
    ->everyMinute()
    ->when(fn () => app(RunScheduledBackup::class)->isDue())
    ->name('pos:backup')
    ->withoutOverlapping(60);

/* ───────────────────────── Updates ─────────────────────────
 * Daily feed check — only when the customer left auto-check on.
 * Read-only: records whether a newer version exists so the admin
 * banner can show it. Never downloads or installs anything.
 */
$daily('update-check', '03:00', function () {
    if (! app_updates()['auto_check'] && ! app_updates()['auto_install']) {
        return;
    }

    app(CheckForUpdate::class)();
    app(AutoInstallIfEligible::class)();
});

/* ───────────────────────── Low stock ───────────────────────
 * Daily sweep for products at/below reorder level → one admin
 * bell notification (deduped while the situation persists).
 */
$daily('low-stock-check', '06:00', fn () => app(CheckLowStock::class)());

/* ─────────────────────── Daily metrics ─────────────────────
 * Nightly rebuild of the daily_metrics pre-aggregation: finalise
 * yesterday, refresh a short trailing window, and recompute any
 * day marked stale by a late void/return. Dashboards + trend
 * reports read this for settled days (live fallback otherwise).
 */
$dailyCommand('daily-metrics', '01:30', 'pos:refresh-daily-metrics');
$dailyCommand('receivables-reconciliation', '02:00', 'pos:accounting-reconcile-receivables');
$dailyCommand('whatsapp-log-retention', '02:15', 'pos:whatsapp-prune');

// Daily idle-install check; active requests also check at the configured interval.
if (config('pos.license.recheck')) {
    $daily('license-recheck', '04:00', fn () => app(RecheckLicense::class)());
}

/* ───────────────────────── Demo reset ──────────────────────
 * Public-demo housekeeping ONLY (gated by POS_DEMO_MODE). Wipes
 * whatever visitors did and restores the captured baseline so the
 * demo stays presentable — visitors stay free to add data, the junk
 * just doesn't outlive the window. No-ops if no baseline has been
 * captured (`pos:demo-snapshot`).
 *
 * Six times a day (`0 * / 4 * * *` — 00:00, 04:00, 08:00, 12:00,
 * 16:00, 20:00 in config('app.timezone')). This is a full RunRestore,
 * not a selective cleanup: it drops every table, so each pass takes
 * the app into maintenance mode and signs out anyone mid-session
 * (`sessions` is not one of RunRestore's preserved tables).
 *
 * `withoutOverlapping()` matters more here than on the old daily
 * cadence — without it a restore running longer than its slot would
 * have a second destructive restore start on top of it. `name()` MUST
 * stay above it: CallbackEvent derives the mutex key from the event
 * description and throws when it's missing.
 */
Schedule::call(fn () => app(ResetDemoData::class)())
    ->everyFourHours()
    ->when(fn () => pos_is_demo())
    ->name('pos:demo-reset')
    ->withoutOverlapping();

/* ──────────────────── Gateway refund retries ───────────────
 * Re-attempts card/Stripe/Razorpay reversals that failed at
 * refund time (gateway outage, transient error). Attempts are
 * capped per return, so a permanently-unrefundable charge stops
 * retrying and waits for a manual "Retry reversal".
 */
Schedule::call(fn () => app(RetryFailedGatewayReversals::class)())
    ->everyFiveMinutes()
    ->name('pos:gateway-refund-retry')
    ->withoutOverlapping();

/* ──────────────────── Loyalty housekeeping ─────────────────
 * Expires points past their expiry date and reconciles the cached
 * `customers.loyalty_points` against the ledger. Both no-op on an
 * install with loyalty off or no expiry configured, so this is cheap
 * to run nightly regardless. Runs after the daily metrics rebuild so
 * a heavy night doesn't stack them.
 */
$dailyCommand('loyalty-maintenance', '02:30', 'pos:loyalty-maintenance');

/* ─────────────── Replay failed journal postings ─────────────
 * Defect #104. The auto-posters swallow failures so a bookkeeping
 * problem can never stop a cashier taking money; PostingGuard
 * records each one in `posting_failures`. This drains that queue
 * once the cause clears on its own (a numbering clash, a lock
 * lifted). Anything still failing stays queued and visible —
 * every poster is idempotent, so a needless replay is a no-op.
 */
$dailyCommand('accounting-repost', '02:45', 'pos:accounting-repost');

/* ──────────────────── Scheduled reports ─────────────────────
 * Every minute, deliver any report schedules that have come due
 * (email the generated PDF/Excel/CSV to their recipients). Cheap
 * when nothing is due — one indexed query. Runs synchronously
 * (no queue) to stay shared-hosting friendly.
 */
Schedule::command('pos:run-scheduled-reports')
    ->everyMinute()
    ->name('pos:scheduled-reports')
    ->withoutOverlapping();

// Optional add-ons may register shared-hosting-safe scheduled work here.
do_action('scheduler.register', Schedule::getFacadeRoot());
