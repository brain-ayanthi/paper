<?php

namespace App\Actions\Sync;

use App\Exceptions\SyncLogNotRetryable;
use App\Models\SyncLog;
use App\Models\User;

/**
 * Dismiss a failed / conflicted sync-log entry that can never succeed —
 * the customer walked out, the stock genuinely wasn't there, the sale
 * was re-rung manually on another terminal.
 *
 * Dismissing does NOT delete anything: the row keeps its payload and its
 * failure message for audit, it just stops counting against the Failed /
 * Conflict tab badges so the owner's "still needs attention" list is
 * actually actionable.
 *
 * Reversible — pass `$undo` to clear the stamp and put the entry back in
 * the queue of things to deal with.
 *
 * Hooks:
 *   - action `sync_log.after_resolve` → ($log, $actor)
 */
class ResolveSyncLog
{
    public function __invoke(SyncLog $log, User $actor, bool $undo = false): SyncLog
    {
        if (! $undo && $log->isSuccess()) {
            throw new SyncLogNotRetryable('already_synced',
                'Successful entries need no resolution.');
        }

        $log->forceFill($undo
            ? ['resolved_at' => null, 'resolved_by' => null]
            : ['resolved_at' => now(), 'resolved_by' => $actor->id]);

        $log->save();

        do_action('sync_log.after_resolve', $log, $actor);

        return $log;
    }
}
