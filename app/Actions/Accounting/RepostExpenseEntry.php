<?php

namespace App\Actions\Accounting;

use App\Actions\Accounting\Concerns\FindsPostedEntry;
use App\Exceptions\EntryNotReversible;
use App\Models\Expense;
use App\Models\JournalEntry;
use Illuminate\Support\Facades\DB;

/**
 * Keeps an expense's journal entry in step with the expense — defects
 * #102 and #103.
 *
 * `PostExpenseEntry` only ever ran on `expense.after_create`. Editing an
 * expense saved the row and stopped there, so changing an amount from 50 to
 * 5,000 left the books showing 50 — and re-categorising it left the cost in
 * the wrong account for good. Deleting one soft-deleted the row and left its
 * entry standing, so the expense vanished from the list while still depressing
 * profit. Both of those are silent: nothing in the UI suggests the books and
 * the record disagree.
 *
 * Correcting the books is a REVERSAL plus a fresh posting, never an in-place
 * edit of a posted entry. A posted journal entry is an immutable historical
 * record — that is the whole point of double-entry — and rewriting one would
 * also silently change a period that may already be locked or reported on.
 *
 * `repost()` handles an edit; `reverse()` handles a delete.
 */
class RepostExpenseEntry
{
    use FindsPostedEntry;

    public function __construct(
        private PostExpenseEntry $post,
        private ReverseJournalEntry $reverseEntry,
    ) {}

    /**
     * Reverse whatever is standing for this expense and post its current
     * figures. Returns the new entry, or null when there is nothing to post
     * (a zero-value expense, say).
     */
    public function repost(Expense $expense): ?JournalEntry
    {
        return DB::transaction(function () use ($expense) {
            $this->reverseLive($expense);

            // The poster's guard is now reversal-aware, so the entry we just
            // cancelled doesn't count as "already posted".
            return ($this->post)($expense->fresh() ?? $expense);
        });
    }

    /**
     * Take the expense out of the books entirely — used when it is deleted.
     * Returns the reversal, or null when there was nothing posted.
     */
    public function reverse(Expense $expense): ?JournalEntry
    {
        return DB::transaction(fn () => $this->reverseLive($expense));
    }

    private function reverseLive(Expense $expense): ?JournalEntry
    {
        $live = $this->livePostedEntry(JournalEntry::SOURCE_EXPENSE, Expense::class, (int) $expense->id);

        if (! $live) {
            return null;
        }

        try {
            return ($this->reverseEntry)($live, JournalEntry::SOURCE_EXPENSE);
        } catch (EntryNotReversible) {
            // Something reversed it between our read and the lock. That is the
            // outcome we wanted anyway.
            return null;
        }
    }
}
