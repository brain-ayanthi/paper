<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Concurrent-login policy — security register SEC #40 (defect #12).
 *
 * `docs/features/auth-users.md` §"Settings → Security" specifies a switch
 * restricting an account to one active session. The setting was documented and
 * never built, so the behaviour it describes simply didn't exist.
 *
 * Off by default: a shop legitimately runs one account on the till and the
 * back-office laptop at once, and flipping this on for every existing install
 * would sign people out mid-shift.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('company', function (Blueprint $table) {
            $table->boolean('enforce_single_session')->default(false)->after('scheduler_cron_token_expires_at');
        });
    }

    public function down(): void
    {
        Schema::table('company', function (Blueprint $table) {
            $table->dropColumn('enforce_single_session');
        });
    }
};
