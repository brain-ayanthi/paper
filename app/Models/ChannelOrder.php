<?php

namespace App\Models;

use App\Casts\SafeEncryptedArray;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Provider order imported before it is allocated to one or more POS stores. */
class ChannelOrder extends Model
{
    public const STATUS_PENDING = 'pending';

    public const STATUS_ALLOCATED = 'allocated';

    public const STATUS_PROCESSING = 'processing';

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_CANCELLED = 'cancelled';

    public const STATUS_FAILED = 'failed';

    protected $fillable = [
        'commerce_connection_id', 'remote_order_id', 'remote_order_number',
        'status', 'financial_status', 'fulfillment_status', 'currency_code',
        'subtotal', 'discount_total', 'tax_total', 'shipping_total',
        'grand_total', 'refunded_total', 'customer_id', 'remote_customer_id',
        'payment_reference', 'billing_address', 'shipping_address', 'payload',
        'placed_at', 'paid_at', 'cancelled_at', 'remote_updated_at',
        'imported_at', 'last_error',
    ];

    protected function casts(): array
    {
        return [
            'subtotal' => 'decimal:4',
            'discount_total' => 'decimal:4',
            'tax_total' => 'decimal:4',
            'shipping_total' => 'decimal:4',
            'grand_total' => 'decimal:4',
            'refunded_total' => 'decimal:4',
            'billing_address' => SafeEncryptedArray::class,
            'shipping_address' => SafeEncryptedArray::class,
            'payload' => SafeEncryptedArray::class,
            'placed_at' => 'datetime',
            'paid_at' => 'datetime',
            'cancelled_at' => 'datetime',
            'remote_updated_at' => 'datetime',
            'imported_at' => 'datetime',
        ];
    }

    public function connection(): BelongsTo
    {
        return $this->belongsTo(CommerceConnection::class, 'commerce_connection_id')->withTrashed();
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(ChannelOrderItem::class);
    }

    public function allocations(): HasMany
    {
        return $this->hasMany(ChannelOrderAllocation::class);
    }
}
