<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Records a journal posting that did not happen — defect #104.
 *
 * Every auto-poster is wired as `try { … } catch (Throwable $e) { report($e); }`.
 * The intent is sound: a bookkeeping problem must never stop a cashier taking
 * money, and the posters are idempotent so an entry can be backfilled. What
 * was missing is the backfill. `report()` writes to the log file and nothing
 * else, so a locked period, a missing account mapping or a numbering clash
 * silently produced a sale with no journal entry — and the first anyone knew
 * was a trial balance that would not balance, weeks later, with no list of
 * what to re-post.
 *
 * Each failure now lands here: what we were posting, for which record, why it
 * failed, and whether it has since been resolved. That gives the operator a
 * visible queue and gives `pos:accounting-repost` something to work from.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('posting_failures', function (Blueprint $table) {
            $table->id();

            // The hook that was firing (e.g. `sale.after_complete`) and the
            // poster that threw — enough to replay it without guessing.
            $table->string('hook', 64);
            $table->string('poster', 191);

            $table->string('reference_type', 191)->nullable();
            $table->unsignedBigInteger('reference_id')->nullable();
            $table->foreignId('store_id')->nullable()->constrained('stores')->nullOnDelete();

            $table->string('exception', 191)->nullable();
            $table->text('message')->nullable();

            // Bumped each time a replay fails again, so a permanently broken
            // one (a mapping nobody has fixed) is distinguishable from a
            // transient clash.
            $table->unsignedInteger('attempts')->default(1);
            $table->timestamp('last_attempted_at')->nullable();
            $table->timestamp('resolved_at')->nullable();

            $table->timestamps();

            // One open row per (poster, record): a nightly retry must not
            // grow the table without bound.
            $table->unique(['poster', 'reference_type', 'reference_id'], 'posting_failures_reference_unique');
            $table->index(['resolved_at', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('posting_failures');
    }
};
