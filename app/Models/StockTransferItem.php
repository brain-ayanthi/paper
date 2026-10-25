<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StockTransferItem extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'stock_transfer_id',
        'product_id', 'variant_id', 'batch_id',
        'requested_quantity', 'received_quantity', 'unit_cost',
        'batch_number_snapshot', 'batch_manufacture_date_snapshot',
        'batch_expiry_date_snapshot', 'batch_cost_price_snapshot',
        'batch_selling_price_snapshot', 'batch_mrp_snapshot',
    ];

    protected function casts(): array
    {
        return [
            'requested_quantity' => 'decimal:4',
            'received_quantity'  => 'decimal:4',
            'unit_cost'          => 'decimal:4',
            'batch_manufacture_date_snapshot' => 'date',
            'batch_expiry_date_snapshot'      => 'date',
            'batch_cost_price_snapshot'       => 'decimal:4',
            'batch_selling_price_snapshot'    => 'decimal:4',
            'batch_mrp_snapshot'              => 'decimal:4',
        ];
    }

    /** @return BelongsTo<StockTransfer, $this> */
    public function transfer(): BelongsTo
    {
        return $this->belongsTo(StockTransfer::class, 'stock_transfer_id');
    }

    /** @return BelongsTo<Product, $this> */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    /** @return BelongsTo<ProductVariant, $this> */
    public function variant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class, 'variant_id');
    }

    /** @return BelongsTo<ProductBatch, $this> */
    public function batch(): BelongsTo
    {
        return $this->belongsTo(ProductBatch::class, 'batch_id');
    }
}
