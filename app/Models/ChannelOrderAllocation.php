<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** The portion of a channel order reserved for one physical POS store. */
class ChannelOrderAllocation extends Model
{
    public const STATUS_PENDING = 'pending';

    public const STATUS_RESERVED = 'reserved';

    public const STATUS_FULFILLED = 'fulfilled';

    public const STATUS_CANCELLED = 'cancelled';

    public const STATUS_FAILED = 'failed';

    protected $fillable = [
        'channel_order_id', 'store_id', 'sale_id', 'remote_fulfillment_id',
        'status', 'subtotal', 'discount_total', 'tax_total', 'shipping_total',
        'grand_total', 'reserved_at', 'fulfilled_at',
    ];

    protected function casts(): array
    {
        return [
            'subtotal' => 'decimal:4',
            'discount_total' => 'decimal:4',
            'tax_total' => 'decimal:4',
            'shipping_total' => 'decimal:4',
            'grand_total' => 'decimal:4',
            'reserved_at' => 'datetime',
            'fulfilled_at' => 'datetime',
        ];
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(ChannelOrder::class, 'channel_order_id');
    }

    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }

    public function sale(): BelongsTo
    {
        return $this->belongsTo(Sale::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(ChannelOrderAllocationItem::class);
    }
}
