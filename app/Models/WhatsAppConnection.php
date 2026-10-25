<?php

namespace App\Models;

use App\Casts\SafeEncryptedArray;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class WhatsAppConnection extends Model
{
    use SoftDeletes;

    protected $table = 'whatsapp_connections';

    public const PROVIDER_META = 'meta';

    public const STATUS_CONNECTED = 'connected';

    public const STATUS_DISCONNECTED = 'disconnected';

    public const STATUS_ERROR = 'error';

    public const STATUS_DISABLED = 'disabled';

    protected $fillable = [
        'store_id', 'provider', 'name', 'sender_phone', 'credentials', 'settings',
        'status', 'last_tested_at', 'last_connected_at', 'last_webhook_at',
        'last_error', 'created_by', 'updated_by',
    ];

    protected $hidden = ['credentials'];

    protected function casts(): array
    {
        return [
            'credentials' => SafeEncryptedArray::class,
            'settings' => 'array',
            'last_tested_at' => 'datetime',
            'last_connected_at' => 'datetime',
            'last_webhook_at' => 'datetime',
        ];
    }

    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }

    public function logs(): HasMany
    {
        return $this->hasMany(WhatsAppLog::class);
    }

    public function scopeActive(Builder $query): void
    {
        $query->where(function (Builder $query) {
            $query->where('status', self::STATUS_CONNECTED)
                ->orWhere(function (Builder $query) {
                    $query->where('settings->mode', 'click_to_chat')
                        ->where('status', '!=', self::STATUS_DISABLED);
                });
        });
    }

    public function setting(string $key, mixed $default = null): mixed
    {
        return data_get($this->settings ?? [], $key, $default);
    }

    public static function forStore(int $storeId): ?self
    {
        return static::query()->active()
            ->where(fn (Builder $query) => $query->where('store_id', $storeId)->orWhereNull('store_id'))
            ->orderByRaw('CASE WHEN store_id = ? THEN 0 ELSE 1 END', [$storeId])
            ->latest('id')
            ->first();
    }
}
