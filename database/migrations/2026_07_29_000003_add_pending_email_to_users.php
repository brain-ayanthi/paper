<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Two-step email change — security register SEC #10 (defect #11).
 *
 * `PATCH /admin/profile` wrote the new address straight onto `users.email`,
 * and the row's `email_verified_at` stayed as it was — so an address nobody
 * had ever proved control of was recorded as verified. Anyone who reached a
 * signed-in session (a shared till left unlocked, a borrowed laptop) could
 * point the account's email at themselves and then take it over through
 * password reset, with the real owner losing the only channel that would have
 * told them.
 *
 * The new address is now parked here until it is confirmed from a link sent
 * to it. `users.email` — the thing that logs in and receives password resets
 * — does not move until then.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('pending_email', 191)->nullable()->after('email');
            // Stored hashed: this token is a bearer credential for an account
            // takeover step, and a leaked database read should not hand one
            // over ready to use.
            $table->string('pending_email_token', 64)->nullable()->after('pending_email');
            $table->timestamp('pending_email_sent_at')->nullable()->after('pending_email_token');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['pending_email', 'pending_email_token', 'pending_email_sent_at']);
        });
    }
};
