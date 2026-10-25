<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One row per recurring task, remembering which run-window it last claimed.
 *
 * This is what turns "run at exactly 02:00" into "run once on or after 02:00".
 * Laravel's scheduler only fires a `dailyAt()` task if `schedule:run` happens
 * to execute during that one minute — so on a host that pauses cron overnight
 * (a routine maintenance window on shared hosting) the task never runs at all,
 * silently, forever. With this table the task simply asks "has this window
 * already been served?" and catches up on the first tick that does happen.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('scheduled_task_runs', function (Blueprint $table) {
            $table->id();

            // Stable identifier from routes/console.php, e.g. 'backup'.
            $table->string('task_key', 64)->unique();

            // The window this row claimed — the most recent scheduled time that
            // has already passed (stored UTC). Dueness is a single comparison
            // against this. DATETIME retains time without implicit TIMESTAMP defaults.
            $table->dateTime('period_started_at');

            // When the attempt actually started, and how it ended.
            $table->dateTime('ran_at');
            $table->string('status', 16)->default('running'); // running | ok | failed
            $table->text('message')->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('scheduled_task_runs');
    }
};
