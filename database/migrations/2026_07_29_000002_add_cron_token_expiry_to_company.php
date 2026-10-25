<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Lifetime for the URL-cron token — security register SEC #101.
 *
 * `scheduler_cron_token` is a 48-char secret that lives in a URL. The endpoint
 * itself is careful (opt-in, `hash_equals`, 404 on mismatch, throttled), but a
 * URL is pasted into crontab, into external pingers like cron-job.org, and
 * from there into proxy and access logs and browser history. Once minted the
 * token stayed valid forever, so a leak through any of those sinks granted
 * "run the scheduled tasks" indefinitely — until an admin happened to notice
 * and revoke by hand.
 *
 *  - `scheduler_cron_token_created_at` lets the settings page show the token's
 *    age and nag when it's stale.
 *  - `scheduler_cron_token_expires_at` is the hard stop. NULL keeps the old
 *    forever behaviour, which stays available because a customer whose only
 *    scheduling option is an external pinger cannot be locked out of backups
 *    by an expiry they forgot about.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('company', function (Blueprint $table) {
            $table->timestamp('scheduler_cron_token_created_at')->nullable()->after('scheduler_cron_token');
            $table->timestamp('scheduler_cron_token_expires_at')->nullable()->after('scheduler_cron_token_created_at');
        });
    }

    public function down(): void
    {
        Schema::table('company', function (Blueprint $table) {
            $table->dropColumn(['scheduler_cron_token_created_at', 'scheduler_cron_token_expires_at']);
        });
    }
};
