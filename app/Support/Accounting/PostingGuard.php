<?php

namespace App\Support\Accounting;

use App\Models\PostingFailure;
use Illuminate\Support\Facades\Log;

/**
 * Runs an auto-poster and, if it throws, makes the failure VISIBLE and
 * REPLAYABLE — defect #104.
 *
 * The existing shape was:
 *
 *     try { app(PostSaleEntry::class)($sale); }
 *     catch (\Throwable $e) { report($e); }
 *
 * Swallowing is the right instinct — a bookkeeping problem must never stop a
 * cashier taking money, and the posters are idempotent so an entry can be
 * backfilled later. What was missing is that "later" never came: `report()`
 * writes to a log file nobody reads, so a locked period, a missing account
 * mapping or a numbering clash produced a sale with no journal entry and no
 * record that one was owed. The first symptom is a trial balance that doesn't
 * balance, weeks on, with nothing to tell you which documents to re-post.
 *
 * Same swallow, but the failure is now written to `posting_failures` (one open
 * row per poster + record), the admins are notified once, and
 * `pos:accounting-repost` can replay the queue. A success clears any open row
 * for that record, so a transient failure that the next attempt fixes leaves
 * nothing behind.
 */
class PostingGuard
{
    /**
     * @param  string    $hook       the hook being serviced, e.g. `sale.after_complete`
     * @param  string    $poster     the poster class, for display + replay
     * @param  callable  $run        does the posting
     * @param  object|null  $reference  the business record, when there is one
     */
    public static function attempt(string $hook, string $poster, callable $run, ?object $reference = null): void
    {
        try {
            $run();
            static::resolve($poster, $reference);
        } catch (\Throwable $e) {
            report($e);
            static::record($hook, $poster, $e, $reference);
        }
    }

    /** Note that this record now posts cleanly. */
    public static function resolve(string $poster, ?object $reference): void
    {
        if (! $reference || ! isset($reference->id)) {
            return;
        }

        try {
            PostingFailure::query()
                ->where('poster', $poster)
                ->where('reference_type', $reference::class)
                ->where('reference_id', $reference->id)
                ->unresolved()
                ->update(['resolved_at' => now()]);
        } catch (\Throwable $e) {
            // Never let bookkeeping HOUSEKEEPING break the business operation
            // either — that would be the same mistake one level up.
            Log::warning('Could not clear a posting failure.', ['error' => $e->getMessage()]);
        }
    }

    private static function record(string $hook, string $poster, \Throwable $e, ?object $reference): void
    {
        try {
            $isNew = false;

            $row = PostingFailure::query()->updateOrCreate(
                [
                    'poster'         => $poster,
                    'reference_type' => $reference ? $reference::class : null,
                    'reference_id'   => $reference->id ?? null,
                ],
                [
                    'hook'              => $hook,
                    'store_id'          => $reference->store_id ?? null,
                    'exception'         => class_basename($e),
                    'message'           => mb_substr($e->getMessage(), 0, 2000),
                    'last_attempted_at' => now(),
                    'resolved_at'       => null,
                ],
            );

            // `attempts` counts tries, so bump it rather than letting
            // updateOrCreate reset it.
            $isNew = $row->wasRecentlyCreated;
            if (! $isNew) {
                $row->increment('attempts');
            }

            // Notify once per record, not once per retry — a nightly replay of
            // a permanently broken mapping must not bury the topbar. The
            // dedupe key carries the failure row id for exactly that reason.
            if ($isNew) {
                notify_admins(
                    'posting_failure:'.$row->id,
                    __('accounting.posting_failure.title'),
                    __('accounting.posting_failure.body', [
                        'poster'    => class_basename($poster),
                        'reference' => $reference ? class_basename($reference).' #'.($reference->id ?? '?') : '—',
                        'error'     => mb_substr($e->getMessage(), 0, 200),
                    ]),
                    'alert',
                    route('admin.accounting.journal.index'),
                    'accounting.view',
                );
            }
        } catch (\Throwable $inner) {
            Log::error('Could not record a posting failure.', [
                'poster' => $poster,
                'error'  => $inner->getMessage(),
            ]);
        }
    }
}
