<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ChannelOrderItem extends Model
{
    protected $fillable = [
        'channel_order_id', 'remote_line_id', 'product_id', 'variant_id',
        'product_name_snapshot', 'sku_snapshot', 'quantity', 'unit_price',
        'discount_amount', 'tax_amount', 'line_total', 'quantity_fulfilled',
        'quantity_refunded', 'metadata',
    ];

    protected function casts(): array
    {
        return [
            'quantity' => 'decimal:4',
            'unit_price' => 'decimal:4',
            'discount_amount' => 'decimal:4',
            'tax_amount' => 'decimal:4',
            'line_total' => 'decimal:4',
            'quantity_fulfilled' => 'decimal:4',
            'quantity_refunded' => 'decimal:4',
            'metadata' => 'array',
        ];
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(ChannelOrder::class, 'channel_order_id');
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function variant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class, 'variant_id');
    }

    public function allocationItems(): HasMany
    {
        return $this->hasMany(ChannelOrderAllocationItem::class);
    }
}
