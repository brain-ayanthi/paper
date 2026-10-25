<?php

namespace App\Actions\Accounting\Concerns;

use App\Models\JournalEntry;
use Illuminate\Database\Eloquent\Model;

/**
 * Shared pieces of the double-entry Post* actions (code-review #37):
 *
 *  - the idempotency guard — "has this document already produced a
 *    journal entry?" — which every Post* action must run before writing
 *    (a re-posted sale would double the books), and which was copied
 *    into eight actions differing only by SOURCE_* constant + model;
 *  - the debit()/credit() one-line builders for journal lines.
 *
 * One copy drifting here corrupts the ledger silently, hence the trait.
 */
trait GuardsAgainstDoublePosting
{
    /** True when a journal entry already references this document. */
    private function alreadyPostedFor(string $source, Model $reference): bool
    {
        return JournalEntry::where('source', $source)
            ->where('reference_type', $reference::class)
            ->where('reference_id', $reference->getKey())
            ->exists();
    }

    /** @return array{account_id:int, debit:string, credit:int, description:string} */
    private function debit(int $accountId, string $amount, string $desc): array
    {
        return ['account_id' => $accountId, 'debit' => $amount, 'credit' => 0, 'description' => $desc];
    }

    /** @return array{account_id:int, debit:int, credit:string, description:string} */
    private function credit(int $accountId, string $amount, string $desc): array
    {
        return ['account_id' => $accountId, 'debit' => 0, 'credit' => $amount, 'description' => $desc];
    }
}
