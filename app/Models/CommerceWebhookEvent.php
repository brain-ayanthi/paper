<?php

namespace App\Models;

use App\Casts\SafeEncryptedArray;
use App\Models\Concerns\UsesUtcTimestamps;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Durable, deduplicated inbox row for one signed provider webhook. */
class CommerceWebhookEvent extends Model
{
    use UsesUtcTimestamps;

    public const STATUS_PENDING = 'pending';

    public const STATUS_PROCESSING = 'processing';

    public const STATUS_PROCESSED = 'processed';

    public const STATUS_FAILED = 'failed';

    public const STATUS_IGNORED = 'ignored';

    protected $fillable = [
        'commerce_connection_id', 'provider', 'event_id', 'topic', 'payload',
        'status', 'attempts', 'available_at', 'processed_at', 'last_error',
        'received_at',
    ];

    protected function casts(): array
    {
        return [
            'payload' => SafeEncryptedArray::class,
            'attempts' => 'integer',
            'available_at' => 'datetime',
            'processed_at' => 'datetime',
            'received_at' => 'datetime',
        ];
    }

    /** @param Builder<CommerceWebhookEvent> $query */
    public function scopeDue(Builder $query): void
    {
        $query->where('status', self::STATUS_PENDING)
            ->where(fn (Builder $due) => $due
                ->whereNull('available_at')
                ->orWhere('available_at', '<=', now('UTC')));
    }

    public function connection(): BelongsTo
    {
        return $this->belongsTo(CommerceConnection::class, 'commerce_connection_id');
    }
}
