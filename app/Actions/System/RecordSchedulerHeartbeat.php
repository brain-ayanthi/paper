<?php

namespace App\Actions\System;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Stamps `company.scheduler_last_run_at` — proof that the customer's cron job
 * actually invoked `schedule:run`.
 *
 * Runs every minute (routes/console.php → `pos:heartbeat`). One narrow UPDATE,
 * no model hydration, no events: this fires 1,440 times a day on every install
 * in the wild, so it stays as close to free as possible.
 *
 * Deliberately written with the query builder rather than Eloquent so it can't
 * trip observers/`updated_at` churn, and wrapped so a half-migrated database
 * (mid-update, or before `/migrate` has been run) degrades to a no-op instead
 * of making every scheduler tick throw.
 *
 * @see ResolveSchedulerStatus — reads this timestamp back and interprets it.
 */
class RecordSchedulerHeartbeat
{
    public function __invoke(): void
    {
        try {
            if (! Schema::hasColumn('company', 'scheduler_last_run_at')) {
                return;
            }

            DB::table('company')->update(['scheduler_last_run_at' => now('UTC')]);

            // Raw update bypasses the model event that busts the per-request
            // memo (code-review #13); clear it so a later read is fresh.
            \App\Models\Company::forgetCurrent();
        } catch (\Throwable) {
            // Never let the heartbeat break the scheduler run it's measuring.
        }
    }
}
