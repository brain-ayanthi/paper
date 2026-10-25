<?php

namespace App\Models;

use App\Casts\SafeEncryptedArray;
use App\Models\Concerns\UsesUtcTimestamps;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Retryable outbound change, persisted before a provider API is contacted. */
class CommerceOutboxEvent extends Model
{
    use UsesUtcTimestamps;

    public const TYPE_FULFILLMENT_SYNC = 'fulfillment.sync';

    public const TYPE_INVENTORY_SYNC = 'inventory.sync';

    public const TYPE_PRODUCT_SYNC = 'product.sync';

    public const TYPE_CUSTOMER_SYNC = 'customer.sync';

    public const REALTIME_TYPES = [
        self::TYPE_FULFILLMENT_SYNC,
        self::TYPE_INVENTORY_SYNC,
    ];

    public const BULK_TYPES = [
        self::TYPE_PRODUCT_SYNC,
        self::TYPE_CUSTOMER_SYNC,
    ];

    public const STATUS_PENDING = 'pending';

    public const STATUS_PROCESSING = 'processing';

    public const STATUS_PROCESSED = 'processed';

    public const STATUS_FAILED = 'failed';

    public const STATUS_SUPERSEDED = 'superseded';

    protected $fillable = [
        'commerce_connection_id', 'event_type', 'aggregate_type',
        'aggregate_id', 'dedupe_key', 'payload', 'status', 'attempts',
        'available_at', 'processed_at', 'last_error',
    ];

    protected function casts(): array
    {
        return [
            'payload' => SafeEncryptedArray::class,
            'aggregate_id' => 'integer',
            'attempts' => 'integer',
            'available_at' => 'datetime',
            'processed_at' => 'datetime',
        ];
    }

    /** @param Builder<CommerceOutboxEvent> $query */
    public function scopeDue(Builder $query): void
    {
        $query->where('status', self::STATUS_PENDING)
            ->where(fn (Builder $due) => $due
                ->whereNull('available_at')
                ->orWhere('available_at', '<=', now('UTC')));
    }

    /** @param Builder<CommerceOutboxEvent> $query */
    public function scopeInProcessingOrder(Builder $query): void
    {
        $query->orderByRaw("CASE event_type
            WHEN 'fulfillment.sync' THEN 10
            WHEN 'inventory.sync' THEN 20
            WHEN 'product.sync' THEN 100
            WHEN 'customer.sync' THEN 110
            ELSE 200 END")
            ->orderBy('id');
    }

    public function connection(): BelongsTo
    {
        return $this->belongsTo(CommerceConnection::class, 'commerce_connection_id')->withTrashed();
    }
}
