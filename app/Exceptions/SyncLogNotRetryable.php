<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * Thrown when a sync-log row can't be replayed — it already succeeded,
 * it was dismissed by an owner, or it isn't an entity the replay path
 * knows how to re-post (only `sale` today).
 *
 * Caught by {@see App\Http\Controllers\Admin\SyncLogController::retry()}
 * and surfaced as a friendly error rather than a 500.
 */
class SyncLogNotRetryable extends RuntimeException
{
    public function __construct(public readonly string $reason, string $message)
    {
        parent::__construct($message);
    }
}
