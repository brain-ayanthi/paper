<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Scheduler (cron) heartbeat + URL-cron token.
 *
 * Everything time-based in the app — auto backups, scheduled reports, low-stock
 * alerts, update checks, daily metrics — only runs if the customer added the
 * `schedule:run` cron job on their server. Until now nothing recorded whether
 * that cron was alive, so an install with no cron looked perfectly healthy
 * while silently doing nothing for days.
 *
 * `scheduler_last_run_at` is stamped every minute by the `pos:heartbeat`
 * schedule entry. Its staleness IS the diagnosis: never set = cron was never
 * installed, stale = it stopped.
 *
 * `scheduler_cron_token` authenticates the URL-cron fallback (GET /cron/{token})
 * for hosts that only offer "fetch a URL on a schedule" rather than shell cron.
 * Nullable — generated on demand from Settings → Scheduler, never by default,
 * so no install carries a live public endpoint it didn't ask for.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('company', function (Blueprint $table) {
            $table->timestamp('scheduler_last_run_at')->nullable()->after('backup_last_run_at');
            $table->string('scheduler_cron_token', 64)->nullable()->after('scheduler_last_run_at');
        });
    }

    public function down(): void
    {
        Schema::table('company', function (Blueprint $table) {
            $table->dropColumn(['scheduler_last_run_at', 'scheduler_cron_token']);
        });
    }
};
