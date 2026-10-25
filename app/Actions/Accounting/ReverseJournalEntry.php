<?php

namespace App\Actions\Accounting;

use App\Exceptions\EntryNotReversible;
use App\Models\JournalEntry;
use Illuminate\Support\Facades\DB;

/**
 * Posts the mirror image of an existing entry — every debit becomes a credit
 * and vice versa — via the canonical {@see PostJournalEntry}. A reversal of a
 * balanced entry is itself balanced, so this can undo any posted entry (a
 * voided sale, a corrected manual entry) without re-deriving the amounts.
 *
 * Links the two entries both ways (`reversal_of_id` / `reversed_by_id`).
 *
 * Eligibility is enforced HERE, not only in the caller (defect #106). The
 * controller checked `is_posted` and `reversed_by_id` before calling, but did
 * so outside any transaction or row lock: two clicks a few milliseconds apart
 * both read "not yet reversed" and both posted a reversal, so the books
 * carried the mirror entry twice and the original's `reversed_by_id` pointed
 * at whichever won the race. Every other caller — a hook, a command, a future
 * API route — had no guard at all.
 *
 * The row is locked and re-read inside a transaction, so the second caller
 * sees the first one's write and is refused.
 */
class ReverseJournalEntry
{
    public function __construct(private PostJournalEntry $post) {}

    /**
     * @param  array<string, mixed>  $overrides  merged over the reversal header
     *                                            (e.g. a different `source` or `entry_date`)
     *
     * @throws EntryNotReversible when the entry is a draft or already reversed
     */
    public function __invoke(JournalEntry $original, string $source, array $overrides = []): JournalEntry
    {
        return DB::transaction(function () use ($original, $source, $overrides) {
            // SELECT … FOR UPDATE. The loser of a double-click blocks here and
            // then reads the winner's `reversed_by_id`.
            $locked = JournalEntry::query()
                ->whereKey($original->getKey())
                ->lockForUpdate()
                ->first();

            if (! $locked) {
                throw new EntryNotReversible('missing');
            }

            // A draft has never hit the books; reversing it would post a
            // mirror of something that was never there.
            if (! $locked->is_posted) {
                throw new EntryNotReversible('not_posted');
            }

            if ($locked->reversed_by_id) {
                throw new EntryNotReversible('already_reversed');
            }

            $locked->loadMissing('lines');

            $lines = $locked->lines->map(fn ($l) => [
                'account_id'  => $l->account_id,
                'debit'       => (string) $l->credit, // swap
                'credit'      => (string) $l->debit,
                'description' => $l->description,
            ])->all();

            $reversal = ($this->post)(array_merge([
                'store_id'       => $locked->store_id,
                'entry_date'     => now()->toDateString(),
                'source'         => $source,
                'reference_type' => $locked->reference_type,
                'reference_id'   => $locked->reference_id,
                'reversal_of_id' => $locked->id,
                'description'    => __('accounting.descriptions.reversal', ['number' => $locked->number]),
                'lines'          => $lines,
            ], $overrides));

            $locked->forceFill(['reversed_by_id' => $reversal->id])->save();

            // Keep the caller's instance in step — it is what the controller
            // redirects with.
            $original->setAttribute('reversed_by_id', $reversal->id);

            return $reversal;
        });
    }
}
