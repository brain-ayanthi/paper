<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Maps one physical POS store to a provider inventory/fulfillment location. */
class CommerceStoreMapping extends Model
{
    public const MODE_LOCATION = 'location';

    public const MODE_AGGREGATE = 'aggregate';

    public const MODE_DISABLED = 'disabled';

    public const INVENTORY_MODES = [self::MODE_LOCATION, self::MODE_AGGREGATE, self::MODE_DISABLED];

    protected $fillable = [
        'commerce_connection_id', 'store_id', 'remote_location_id',
        'remote_location_name', 'inventory_mode', 'fulfillment_priority',
        'safety_stock', 'sync_inventory', 'fulfill_orders', 'is_active',
        'settings',
    ];

    protected function casts(): array
    {
        return [
            'fulfillment_priority' => 'integer',
            'safety_stock' => 'decimal:4',
            'sync_inventory' => 'boolean',
            'fulfill_orders' => 'boolean',
            'is_active' => 'boolean',
            'settings' => 'array',
        ];
    }

    /** @param Builder<CommerceStoreMapping> $query */
    public function scopeActive(Builder $query): void
    {
        $query->where('is_active', true);
    }

    public function connection(): BelongsTo
    {
        return $this->belongsTo(CommerceConnection::class, 'commerce_connection_id');
    }

    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }
}
