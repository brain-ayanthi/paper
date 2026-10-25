<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A business expense — money paid out (rent, utilities, supplies, …).
 * The drawer's cash-out side. `number` is auto-generated per store;
 * `status` defaults to `approved` (the approval workflow + recurring +
 * journal posting are deferred). Money is DECIMAL(15,4) end-to-end.
 */
class Expense extends Model
{
    use SoftDeletes;

    public const STATUS_PENDING  = 'pending';
    public const STATUS_APPROVED = 'approved';
    public const STATUS_REJECTED = 'rejected';

    /** User-editable fields. `number`, `status`, audit cols are forceFilled. */
    protected $fillable = [
        'category_id',
        'expense_date',
        'amount',
        'tax_amount',
        'payment_method_id',
        'supplier_id',
        'reference',
        'description',
        'receipt_image_path',
    ];

    protected function casts(): array
    {
        return [
            'expense_date' => 'date',
            'amount'       => 'decimal:4',
            'tax_amount'   => 'decimal:4',
            'is_recurring' => 'boolean',
            'approved_at'  => 'datetime',
        ];
    }

    /** Total cash leaving the drawer for this expense. */
    public function getTotalAttribute(): string
    {
        return bcadd((string) ($this->amount ?? '0'), (string) ($this->tax_amount ?? '0'), 4);
    }

    /* ── Relationships ──────────────────────────────────────────── */

    /** @return BelongsTo<Store, $this> */
    public function store(): BelongsTo { return $this->belongsTo(Store::class); }

    /** @return BelongsTo<ExpenseCategory, $this> */
    public function category(): BelongsTo { return $this->belongsTo(ExpenseCategory::class, 'category_id'); }

    /** @return BelongsTo<PaymentMethod, $this> */
    public function paymentMethod(): BelongsTo { return $this->belongsTo(PaymentMethod::class); }

    /** @return BelongsTo<Supplier, $this> */
    public function supplier(): BelongsTo { return $this->belongsTo(Supplier::class); }

    /** @return BelongsTo<User, $this> */
    public function creator(): BelongsTo { return $this->belongsTo(User::class, 'created_by'); }

    /**
     * The cash-drawer pay-out that took this money out of a till, if the
     * expense was paid from one. Absent for expenses paid by bank transfer,
     * or paid in cash outside any open shift (office petty cash, not a till).
     *
     * Written by {@see App\Actions\Expenses\SyncExpenseTillPayOut} — the
     * expense row itself deliberately carries no `shift_id`, so this relation
     * is the single link between the two sides.
     *
     * @return HasOne<CashDrawerEntry, $this>
     */
    public function drawerPayOut(): HasOne
    {
        return $this->hasOne(CashDrawerEntry::class)
            ->where('type', CashDrawerEntry::TYPE_PAY_OUT);
    }

    /* ── Scopes ─────────────────────────────────────────────────── */

    /** @param Builder<Expense> $q */
    public function scopeForStore(Builder $q, int $storeId): void { $q->where('store_id', $storeId); }

    /** @param Builder<Expense> $q */
    public function scopeOrdered(Builder $q): void { $q->orderByDesc('expense_date')->orderByDesc('id'); }
}
