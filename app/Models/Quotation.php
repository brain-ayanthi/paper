<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Quotation extends Model
{
    use SoftDeletes;

    public const STATUS_DRAFT = 'draft';

    public const STATUS_SENT = 'sent';

    public const STATUS_ACCEPTED = 'accepted';

    public const STATUS_DECLINED = 'declined';

    public const STATUS_CANCELLED = 'cancelled';

    public const STATUS_CONVERTED = 'converted';

    public const STATUS_SUPERSEDED = 'superseded';

    public const STATUSES = [
        self::STATUS_DRAFT,
        self::STATUS_SENT,
        self::STATUS_ACCEPTED,
        self::STATUS_DECLINED,
        self::STATUS_CANCELLED,
        self::STATUS_CONVERTED,
        self::STATUS_SUPERSEDED,
    ];

    protected $fillable = [
        'store_id', 'customer_id', 'revision_of_id', 'converted_sale_id',
        'number', 'revision', 'status', 'issue_date', 'valid_until',
        'currency_code', 'exchange_rate_to_base',
        'customer_snapshot', 'billing_address_snapshot', 'shipping_address_snapshot',
        'subtotal', 'discount_total', 'tax_total', 'grand_total',
        'expected_delivery_date', 'terms', 'notes', 'internal_notes',
        'sent_at', 'first_viewed_at', 'last_viewed_at', 'view_count',
        'accepted_at', 'declined_at', 'cancelled_at', 'converted_at',
        'converted_by', 'created_by', 'updated_by',
    ];

    protected function casts(): array
    {
        return [
            'issue_date' => 'date',
            'valid_until' => 'date',
            'expected_delivery_date' => 'date',
            'exchange_rate_to_base' => 'decimal:10',
            'customer_snapshot' => 'array',
            'billing_address_snapshot' => 'array',
            'shipping_address_snapshot' => 'array',
            'subtotal' => 'decimal:4',
            'discount_total' => 'decimal:4',
            'tax_total' => 'decimal:4',
            'grand_total' => 'decimal:4',
            'sent_at' => 'datetime',
            'first_viewed_at' => 'datetime',
            'last_viewed_at' => 'datetime',
            'view_count' => 'integer',
            'accepted_at' => 'datetime',
            'declined_at' => 'datetime',
            'cancelled_at' => 'datetime',
            'converted_at' => 'datetime',
            'revision' => 'integer',
        ];
    }

    public function displayNumber(): string
    {
        return $this->revision > 1 ? $this->number.'-R'.$this->revision : $this->number;
    }

    public function isExpired(): bool
    {
        return $this->valid_until !== null
            && $this->valid_until->isBefore(today())
            && in_array($this->status, [self::STATUS_DRAFT, self::STATUS_SENT], true);
    }

    public function effectiveStatus(): string
    {
        return $this->isExpired() ? 'expired' : $this->status;
    }

    public function isEditable(): bool
    {
        return $this->status === self::STATUS_DRAFT && ! $this->isExpired();
    }

    public function canSend(): bool
    {
        return ! $this->isExpired()
            && in_array($this->status, [self::STATUS_DRAFT, self::STATUS_SENT], true);
    }

    public function canRevise(): bool
    {
        return in_array($this->status, [self::STATUS_SENT, self::STATUS_ACCEPTED, self::STATUS_DECLINED], true);
    }

    public function canCancel(): bool
    {
        return ! $this->isExpired()
            && in_array($this->status, [self::STATUS_DRAFT, self::STATUS_SENT, self::STATUS_ACCEPTED], true)
            && $this->converted_sale_id === null;
    }

    public function canConvert(): bool
    {
        return ! $this->isExpired()
            && in_array($this->status, [self::STATUS_DRAFT, self::STATUS_SENT, self::STATUS_ACCEPTED], true)
            && $this->converted_sale_id === null;
    }

    /** @param Builder<Quotation> $query */
    public function scopeForStore(Builder $query, int $storeId): void
    {
        $query->where('store_id', $storeId);
    }

    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function updater(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    public function converter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'converted_by');
    }

    public function convertedSale(): BelongsTo
    {
        return $this->belongsTo(Sale::class, 'converted_sale_id');
    }

    public function revisionOf(): BelongsTo
    {
        return $this->belongsTo(self::class, 'revision_of_id');
    }

    public function items(): HasMany
    {
        return $this->hasMany(QuotationItem::class)->orderBy('sort_order')->orderBy('id');
    }

    public function activities(): HasMany
    {
        return $this->hasMany(QuotationActivity::class)->latest('id');
    }

    public function publicLinks(): MorphMany
    {
        return $this->morphMany(ReceiptPublicLink::class, 'reference');
    }

    public function ensurePublicLink(): ReceiptPublicLink
    {
        $days = ReceiptPublicLink::DEFAULT_EXPIRY_DAYS;
        if ($this->valid_until) {
            $days = max($days, (int) ceil(now()->diffInDays($this->valid_until, false)) + 30);
        }

        return ReceiptPublicLink::for($this, $days);
    }

    public function publicUrl(): string
    {
        return route('quotation.public', ['token' => $this->ensurePublicLink()->token]);
    }
}
