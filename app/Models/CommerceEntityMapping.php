<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/** Permanent local-to-remote identity mapping; SKU is only an onboarding hint. */
class CommerceEntityMapping extends Model
{
    public const TYPE_PRODUCT = 'product';

    public const TYPE_VARIANT = 'variant';

    public const TYPE_CATEGORY = 'category';

    public const TYPE_BRAND = 'brand';

    public const TYPE_CUSTOMER = 'customer';

    public const TYPE_ORDER = 'order';

    protected $fillable = [
        'commerce_connection_id', 'entity_type', 'local_type', 'local_id',
        'remote_id', 'remote_parent_id', 'local_hash', 'remote_hash',
        'remote_updated_at', 'last_synced_at', 'metadata',
    ];

    protected function casts(): array
    {
        return [
            'remote_updated_at' => 'datetime',
            'last_synced_at' => 'datetime',
            'metadata' => 'array',
        ];
    }

    public function connection(): BelongsTo
    {
        return $this->belongsTo(CommerceConnection::class, 'commerce_connection_id');
    }

    public function local(): MorphTo
    {
        return $this->morphTo(__FUNCTION__, 'local_type', 'local_id');
    }
}
