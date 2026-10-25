<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One refunded line under a {@see SaleReturn}.
 *
 * `restock` is nullable — null = inherit the header's flag, true/false
 * = override per line (e.g. refund a damaged item without restocking
 * while restocking the rest of the order).
 */
class SaleReturnItem extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'sale_return_id', 'sale_item_id',
        'quantity', 'unit_price_snapshot', 'tax_amount', 'line_total',
        'restock', 'notes',
    ];

    protected function casts(): array
    {
        return [
            'quantity'            => 'decimal:4',
            'unit_price_snapshot' => 'decimal:4',
            'tax_amount'          => 'decimal:4',
            'line_total'          => 'decimal:4',
            'restock'             => 'boolean',
        ];
    }

    /** @return BelongsTo<SaleReturn, $this> */
    public function saleReturn(): BelongsTo
    {
        return $this->belongsTo(SaleReturn::class);
    }

    /** @return BelongsTo<SaleItem, $this> */
    public function saleItem(): BelongsTo
    {
        return $this->belongsTo(SaleItem::class);
    }
}
