<?php

namespace App\Actions\Accounting\Concerns;

use App\Models\JournalEntry;

/**
 * "Is this business record already in the books, and which entry is it?"
 *
 * Every poster used to answer that with a bare `exists()` on
 * source + reference. That is right for a record which is posted once and
 * never changes, and wrong the moment anything can be edited or deleted
 * (defects #102 / #103): once an entry has been reversed, the reference has
 * TWO rows against it — the original and its mirror — and a plain `exists()`
 * reads that as "already posted" and refuses to post the corrected figures.
 * The books then keep a reversal with nothing to replace it.
 *
 * The distinction that matters is LIVE vs historical:
 *
 *   - a reversal has `reversal_of_id` set — it exists to cancel something;
 *   - a reversed entry has `reversed_by_id` set — it has been cancelled.
 *
 * Neither is live. What's left is the entry currently standing for this
 * record, and there is at most one.
 */
trait FindsPostedEntry
{
    /**
     * The entry currently representing this record in the books, or null when
     * it has never been posted (or its only entry has since been reversed).
     */
    protected function livePostedEntry(string $source, string $referenceType, int $referenceId): ?JournalEntry
    {
        return JournalEntry::query()
            ->where('source', $source)
            ->where('reference_type', $referenceType)
            ->where('reference_id', $referenceId)
            ->whereNull('reversal_of_id')
            ->whereNull('reversed_by_id')
            ->orderByDesc('id')
            ->first();
    }

    /** Convenience wrapper for the posters' idempotency guard. */
    protected function alreadyPostedFor(string $source, string $referenceType, int $referenceId): bool
    {
        return $this->livePostedEntry($source, $referenceType, $referenceId) !== null;
    }
}
