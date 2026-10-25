<?php

namespace App\Models;

use App\Casts\SafeEncryptedArray;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/** One WooCommerce or Shopify store connected to this POS installation. */
class CommerceConnection extends Model
{
    use SoftDeletes;

    public const PROVIDER_WOOCOMMERCE = 'woocommerce';

    public const PROVIDER_SHOPIFY = 'shopify';

    public const PROVIDERS = [self::PROVIDER_WOOCOMMERCE, self::PROVIDER_SHOPIFY];

    public const STATUS_DISCONNECTED = 'disconnected';

    public const STATUS_CONNECTED = 'connected';

    public const STATUS_ERROR = 'error';

    public const STATUS_DISABLED = 'disabled';

    protected $fillable = [
        'provider', 'name', 'shop_url', 'external_store_id', 'api_version',
        'credentials', 'settings', 'status', 'last_tested_at',
        'last_connected_at', 'last_webhook_at', 'last_reconciled_at',
        'last_error', 'created_by', 'updated_by',
    ];

    protected function casts(): array
    {
        return [
            'credentials' => SafeEncryptedArray::class,
            'settings' => 'array',
            'last_tested_at' => 'datetime',
            'last_connected_at' => 'datetime',
            'last_webhook_at' => 'datetime',
            'last_reconciled_at' => 'datetime',
        ];
    }

    public function isConnected(): bool
    {
        return $this->status === self::STATUS_CONNECTED;
    }

    public function providerLabel(): string
    {
        return match ($this->provider) {
            self::PROVIDER_SHOPIFY => __('commerce.providers.shopify'),
            default => __('commerce.providers.woocommerce'),
        };
    }

    /** @param Builder<CommerceConnection> $query */
    public function scopeOrdered(Builder $query): void
    {
        $query->orderBy('provider')->orderBy('name');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function updater(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    public function storeMappings(): HasMany
    {
        return $this->hasMany(CommerceStoreMapping::class);
    }

    public function entityMappings(): HasMany
    {
        return $this->hasMany(CommerceEntityMapping::class);
    }

    public function webhookEvents(): HasMany
    {
        return $this->hasMany(CommerceWebhookEvent::class);
    }

    public function outboxEvents(): HasMany
    {
        return $this->hasMany(CommerceOutboxEvent::class);
    }

    public function syncRuns(): HasMany
    {
        return $this->hasMany(CommerceSyncRun::class);
    }

    public function channelOrders(): HasMany
    {
        return $this->hasMany(ChannelOrder::class);
    }
}
