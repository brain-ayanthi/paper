<?php

namespace App\Models;

use App\Models\Concerns\MasksDemoEmail;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Customer record — who's buying. Company-level (visible across stores),
 * with the first store of contact recorded for attribution reporting.
 *
 * v1.0 MVP fields. The derived balances (`outstanding_balance`,
 * `store_credit_balance`, `loyalty_points`) are kept on the row for
 * performance but reconciled from `customer_credit_transactions` by a
 * daily job once Sales lands. In this slice they default to 0.
 *
 * See `docs/features/customers.md` for the full spec.
 */
class Customer extends Model
{
    use MasksDemoEmail, SoftDeletes;

    protected $fillable = [
        'code', 'local_uuid', 'name',
        'is_business', 'business_name',
        'email', 'phone', 'whatsapp_phone', 'whatsapp_opt_out',
        'dob', 'gender',
        'gstin', 'pan', 'tax_registration_number',
        'customer_group_id', 'default_discount_percent',
        'credit_limit',
        // outstanding_balance / store_credit_balance / loyalty_points are
        // deliberately NOT fillable (SEC #23 / defect #47). They are LEDGER
        // totals — the running result of sales, payments, returns and credit
        // transactions. While mass-assignable, a CSV import column or a
        // crafted form field could set a customer's debt to zero, or hand
        // them store credit, with no transaction behind it and nothing in the
        // statement to explain the jump. The actions that move money write
        // them explicitly via forceFill(); everything else must go through a
        // transaction. `credit_limit` stays fillable: it is a policy setting
        // the owner chooses, not a balance.
        'first_store_id', 'meta', 'notes', 'is_active',
        'created_by', 'updated_by',
    ];

    protected function casts(): array
    {
        return [
            'is_business'              => 'boolean',
            'whatsapp_opt_out'         => 'boolean',
            'is_active'                => 'boolean',
            'dob'                      => 'date',
            'default_discount_percent' => 'decimal:4',
            'credit_limit'             => 'decimal:4',
            'outstanding_balance'      => 'decimal:4',
            'store_credit_balance'     => 'decimal:4',
            'loyalty_points'           => 'integer',
            'meta'                     => 'array',
        ];
    }

    /** @param Builder<Customer> $q */
    public function scopeActive(Builder $q): void
    {
        $q->where('is_active', true);
    }

    /** @return BelongsTo<CustomerGroup, $this> */
    public function group(): BelongsTo
    {
        return $this->belongsTo(CustomerGroup::class, 'customer_group_id');
    }

    /** @return BelongsTo<Store, $this> */
    public function firstStore(): BelongsTo
    {
        return $this->belongsTo(Store::class, 'first_store_id');
    }

    /** @return HasMany<CustomerAddress, $this> */
    public function addresses(): HasMany
    {
        return $this->hasMany(CustomerAddress::class);
    }

    /** @return HasMany<Quotation, $this> */
    public function quotations(): HasMany
    {
        return $this->hasMany(Quotation::class);
    }

    /** Store-credit + loyalty-point movements, newest first. */
    /** @return HasMany<CustomerCreditTransaction, $this> */
    public function creditTransactions(): HasMany
    {
        return $this->hasMany(CustomerCreditTransaction::class)->latest('id');
    }

    /** Just the loyalty-point movements. @return HasMany<CustomerCreditTransaction, $this> */
    public function loyaltyLedger(): HasMany
    {
        return $this->creditTransactions()->points();
    }

    /** The default address row, or null if none flagged. */
    public function defaultAddress(): ?CustomerAddress
    {
        return $this->addresses()->where('is_default', true)->first();
    }

    /** @return BelongsTo<User, $this> */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** @return BelongsTo<User, $this> */
    public function updater(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }
}
