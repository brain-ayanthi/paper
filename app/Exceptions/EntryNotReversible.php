<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * A journal entry cannot be reversed — defect #106.
 *
 * Carries a machine-readable `$reason` so the caller can pick the right
 * message without string-matching:
 *
 *   missing           the row disappeared between read and lock
 *   not_posted        still a draft; it has never hit the books
 *   already_reversed  something got there first (usually a double-click)
 */
class EntryNotReversible extends RuntimeException
{
    public function __construct(public readonly string $reason)
    {
        parent::__construct(__('accounting.journal.'.match ($reason) {
            'not_posted'       => 'not_posted',
            'already_reversed' => 'already_reversed',
            default            => 'not_reversible',
        }));
    }
}
