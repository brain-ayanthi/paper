<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ChannelOrderAllocationItem extends Model
{
    protected $fillable = [
        'channel_order_allocation_id', 'channel_order_item_id', 'quantity',
        'quantity_fulfilled', 'quantity_refunded',
    ];

    protected function casts(): array
    {
        return [
            'quantity' => 'decimal:4',
            'quantity_fulfilled' => 'decimal:4',
            'quantity_refunded' => 'decimal:4',
        ];
    }

    public function allocation(): BelongsTo
    {
        return $this->belongsTo(ChannelOrderAllocation::class, 'channel_order_allocation_id');
    }

    public function orderItem(): BelongsTo
    {
        return $this->belongsTo(ChannelOrderItem::class, 'channel_order_item_id');
    }
}
