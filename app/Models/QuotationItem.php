<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class QuotationItem extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'quotation_id', 'product_id', 'variant_id',
        'product_name_snapshot', 'variant_name_snapshot', 'sku_snapshot',
        'barcode_snapshot', 'hsn_snapshot', 'quantity', 'unit', 'unit_price',
        'discount_percent', 'discount_amount', 'tax_group_id', 'tax_breakdown',
        'tax_amount', 'line_subtotal', 'line_total', 'notes', 'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'tax_breakdown' => 'array',
            'quantity' => 'decimal:4',
            'unit_price' => 'decimal:4',
            'discount_percent' => 'decimal:4',
            'discount_amount' => 'decimal:4',
            'tax_amount' => 'decimal:4',
            'line_subtotal' => 'decimal:4',
            'line_total' => 'decimal:4',
            'sort_order' => 'integer',
        ];
    }

    public function quotation(): BelongsTo
    {
        return $this->belongsTo(Quotation::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function variant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class);
    }

    public function taxGroup(): BelongsTo
    {
        return $this->belongsTo(TaxGroup::class);
    }
}
