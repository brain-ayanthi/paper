<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Carbon;

/**
 * One movement in a customer's non-cash ledger — either store credit
 * (money, `amount`) or loyalty points (`points`). Both live in the same
 * table because they answer the same question ("what did this customer
 * accrue and spend that wasn't cash?") and share a shape.
 *
 * Every row is append-only and carries the resulting balance
 * (`balance_after_amount` / `balance_after_points`), so the ledger can be
 * read without replaying it and the daily reconciliation job can detect
 * drift against `customers.store_credit_balance` / `customers.loyalty_points`.
 *
 * Points rows use `points` (signed: earn positive, redeem/expire negative)
 * and leave `amount` null. Store-credit rows do the reverse. See
 * `docs/features/customers.md` §9.
 */
class CustomerCreditTransaction extends Model
{
    /** Append-only ledger — `created_at` only (see the migration). */
    public const UPDATED_AT = null;

    // ── Points types.
    public const TYPE_POINTS_EARN    = 'points_earn';
    public const TYPE_POINTS_REDEEM  = 'points_redeem';
    public const TYPE_POINTS_EXPIRE  = 'points_expire';
    public const TYPE_POINTS_ADJUST  = 'points_adjust';
    /** Clawback when the earning sale is voided or refunded. */
    public const TYPE_POINTS_REVERSE = 'points_reverse';

    // ── Store-credit types (reserved — the credit ledger lands with its
    // own slice; listed here so the vocabulary stays in one place).
    public const TYPE_CREDIT_ADD    = 'credit_add';
    public const TYPE_CREDIT_USE    = 'credit_use';
    public const TYPE_CREDIT_ADJUST = 'credit_adjust';
    /** One-time transfer of a historical negative A/R balance into store credit. */
    public const TYPE_CREDIT_CONVERT = 'credit_convert';

    /** Every points-bearing type, for the ledger scope + reconciliation. */
    public const POINTS_TYPES = [
        self::TYPE_POINTS_EARN,
        self::TYPE_POINTS_REDEEM,
        self::TYPE_POINTS_EXPIRE,
        self::TYPE_POINTS_ADJUST,
        self::TYPE_POINTS_REVERSE,
    ];

    protected $fillable = [
        'customer_id', 'store_id', 'type',
        'amount', 'points',
        'balance_after_amount', 'balance_after_points',
        'reference_type', 'reference_id',
        'expires_at', 'notes', 'created_by',
    ];

    protected function casts(): array
    {
        return [
            'amount'               => 'decimal:4',
            'balance_after_amount' => 'decimal:4',
            'points'               => 'integer',
            'balance_after_points' => 'integer',
            'expires_at'           => 'datetime',
            'created_at'           => 'datetime',
        ];
    }

    /** @param Builder<CustomerCreditTransaction> $q */
    public function scopePoints(Builder $q): void
    {
        $q->whereIn('type', self::POINTS_TYPES);
    }

    /** Read the UTC database digits without applying the process timezone. */
    public function expiresAtUtc(): ?Carbon
    {
        $raw = $this->getRawOriginal('expires_at');

        return $raw === null || $raw === ''
            ? null
            : Carbon::createFromFormat($this->getDateFormat(), (string) $raw, 'UTC');
    }

    /** @return BelongsTo<Customer, $this> */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    /** @return BelongsTo<Store, $this> */
    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }

    /** @return BelongsTo<User, $this> */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** The sale / return / adjustment this movement came from, if any. */
    public function reference(): MorphTo
    {
        return $this->morphTo(__FUNCTION__, 'reference_type', 'reference_id');
    }
}
