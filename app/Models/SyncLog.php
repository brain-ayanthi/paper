<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Record of one offline-completed write hitting the server.
 *
 * Written by the cashier's sale-complete endpoint on every POST that
 * carries a `local_uuid` (i.e. anything coming from the offline sync
 * queue). The row exists so owners can see what happened during an
 * outage — successes, failures, conflicts — without grepping the
 * server log.
 *
 * `local_uuid` is UNIQUE: retries from the sync engine `updateOrCreate`
 * onto the existing row so the log doesn't fan out.
 *
 *   result ∈ { 'success', 'failed', 'conflict' }
 *   entity ∈ { 'sale', 'customer', 'return' (Slice 4+), ... }
 */
class SyncLog extends Model
{
    public const UPDATED_AT = null;        // append-only model — created_at + synced_at only

    public const RESULT_SUCCESS  = 'success';
    public const RESULT_FAILED   = 'failed';
    public const RESULT_CONFLICT = 'conflict';

    protected $fillable = [
        'terminal_id', 'user_id',
        'local_uuid', 'entity',
        'payload', 'result', 'result_message',
        'synced_entity_id',
        'synced_at',
        'resolved_at', 'resolved_by',
    ];

    protected function casts(): array
    {
        return [
            'payload'     => 'array',
            'synced_at'   => 'datetime',
            'resolved_at' => 'datetime',
        ];
    }

    public function isSuccess(): bool  { return $this->result === self::RESULT_SUCCESS; }
    public function isFailed(): bool   { return $this->result === self::RESULT_FAILED; }
    public function isConflict(): bool { return $this->result === self::RESULT_CONFLICT; }

    /** Dismissed by an owner, or cleared by a successful retry. */
    public function isResolved(): bool { return $this->resolved_at !== null; }

    /**
     * Can this row be replayed against the server?
     *
     * Mirrors the guard in {@see App\Actions\Sync\RetrySyncLog} so the UI
     * can decide whether to render the button without duplicating rules.
     * Only `sale` entities carry a replayable payload today — kiosk
     * orders only ever log successes.
     */
    public function isRetryable(): bool
    {
        return ! $this->isSuccess() && ! $this->isResolved() && $this->entity === 'sale';
    }

    /** @param Builder<SyncLog> $q */
    public function scopeFailed(Builder $q): void   { $q->where('result', self::RESULT_FAILED); }

    /** @param Builder<SyncLog> $q */
    public function scopeConflict(Builder $q): void { $q->where('result', self::RESULT_CONFLICT); }

    /**
     * Still needs a human — not yet dismissed and not yet retried into
     * success. What the Failed / Conflict tab badges count.
     *
     * @param Builder<SyncLog> $q
     */
    public function scopeUnresolved(Builder $q): void { $q->whereNull('resolved_at'); }

    /** @param Builder<SyncLog> $q */
    public function scopeSuccess(Builder $q): void  { $q->where('result', self::RESULT_SUCCESS); }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return BelongsTo<User, $this> */
    public function resolver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'resolved_by');
    }
}
