<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Pay-in / pay-out / drawer-open-no-sale event against an open shift.
 *
 * These rows are the ONLY thing that moves a shift's expected cash besides
 * sales and refunds ({@see App\Actions\Shifts\ComputeShiftTotals}), which is
 * why every flow that takes physical cash out of a till has to write one:
 * supplier payments from the till, and — since the expense/till linkage —
 * cash expenses ({@see App\Actions\Expenses\SyncExpenseTillPayOut}).
 *
 * `category` applies to pay-outs only (docs/features/cash-drawer-shifts.md
 * §4.3). `CATEGORY_EXPENSE` is the one with teeth: those rows carry an
 * `expense_id`, so petty cash spent at the till lands in the Expenses list
 * and the P&L instead of vanishing into a free-text reason.
 */
class CashDrawerEntry extends Model
{
    public const TYPE_PAY_IN              = 'pay_in';
    public const TYPE_PAY_OUT             = 'pay_out';
    public const TYPE_DRAWER_OPEN_NO_SALE = 'drawer_open_no_sale';

    public const CATEGORY_EXPENSE        = 'expense';
    public const CATEGORY_BANK_DEPOSIT   = 'bank_deposit';
    public const CATEGORY_CHANGE_TO_SAFE = 'change_to_safe';
    public const CATEGORY_OTHER          = 'other';

    /** @return array<int, string> */
    public static function payOutCategories(): array
    {
        return [
            self::CATEGORY_EXPENSE,
            self::CATEGORY_BANK_DEPOSIT,
            self::CATEGORY_CHANGE_TO_SAFE,
            self::CATEGORY_OTHER,
        ];
    }

    public $timestamps = false;

    protected $fillable = [
        'shift_id', 'type', 'category', 'amount', 'reason', 'expense_id', 'supplier_payment_uuid', 'created_by',
    ];

    protected function casts(): array
    {
        return [
            'amount'     => 'decimal:4',
            'created_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Shift, $this> */
    public function shift(): BelongsTo { return $this->belongsTo(Shift::class); }

    /** @return BelongsTo<User, $this> */
    public function createdBy(): BelongsTo { return $this->belongsTo(User::class, 'created_by'); }

    /**
     * The expense this pay-out paid for, if any. Nullable on delete, so a
     * deleted expense leaves the drawer history intact with a dangling null.
     *
     * @return BelongsTo<Expense, $this>
     */
    public function expense(): BelongsTo { return $this->belongsTo(Expense::class); }
}
