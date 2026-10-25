<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Audit/progress row for an import, export, or reconciliation run. */
class CommerceSyncRun extends Model
{
    public const STATUS_PENDING = 'pending';

    public const STATUS_RUNNING = 'running';

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_FAILED = 'failed';

    protected $fillable = [
        'commerce_connection_id', 'kind', 'direction', 'status', 'counters',
        'cursor', 'last_error', 'started_at', 'finished_at', 'requested_by',
    ];

    protected function casts(): array
    {
        return [
            'counters' => 'array',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }

    public function connection(): BelongsTo
    {
        return $this->belongsTo(CommerceConnection::class, 'commerce_connection_id');
    }

    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }
}
