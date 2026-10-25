<?php

namespace App\Actions\Shifts;

use App\Actions\Expenses\CreateExpense;
use App\Models\CashDrawerEntry;
use App\Models\Expense;
use App\Models\PaymentMethod;
use App\Models\Shift;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * A pay-out categorised as `expense` — record the expense AND the drawer
 * movement in one step (docs/features/cash-drawer-shifts.md §4.3).
 *
 * This is the flow the spec always wanted and the reason
 * `cash_drawer_entries.expense_id` existed unused since the first migration:
 * without it, petty cash spent from the till (snacks, parking, a courier)
 * left the drawer as an untyped free-text pay-out and never reached the
 * Expenses list, the expense export, or the P&L.
 *
 * Order matters — the expense is created first so the drawer entry can point
 * at it. Both live in one transaction, so a failure can't leave a pay-out
 * with a dangling expense or vice versa.
 *
 * The expense's journal entry (Dr Expense / Cr Cash on Hand) is posted by the
 * existing `expense.after_create` hook. The pay-out itself posts nothing, so
 * the cash credit lands exactly once.
 */
class RecordPayOutExpense
{
    public function __construct(
        private readonly CreateExpense $createExpense,
        private readonly RecordCashDrawerEntry $recordEntry,
    ) {}

    /**
     * @param array<string, mixed> $data {
     *   amount: numeric-string,
     *   reason: string,
     *   expense_category_id?: ?int,
     *   reference?: ?string,
     * }
     */
    public function __invoke(Shift $shift, array $data, User $user): CashDrawerEntry
    {
        return DB::transaction(function () use ($shift, $data, $user) {
            $expense = ($this->createExpense)([
                'expense_date'      => now()->toDateString(),
                'category_id'       => $data['expense_category_id'] ?? null,
                'amount'            => (string) ($data['amount'] ?? '0'),
                'tax_amount'        => '0',
                'payment_method_id' => $this->cashMethodId(),
                'reference'         => $data['reference'] ?? null,
                // The pay-out reason IS the expense description — the cashier
                // shouldn't have to type the same thing twice.
                'description'       => (string) ($data['reason'] ?? ''),
            ], $user, (int) $shift->store_id);

            return ($this->recordEntry)($shift, [
                'type'       => CashDrawerEntry::TYPE_PAY_OUT,
                'category'   => CashDrawerEntry::CATEGORY_EXPENSE,
                'amount'     => (string) ($data['amount'] ?? '0'),
                'reason'     => $this->reasonFor($expense, (string) ($data['reason'] ?? '')),
                'expense_id' => (int) $expense->id,
            ], $user);
        });
    }

    /**
     * The expense has to be booked against a cash payment method, or
     * `PostExpenseEntry` would credit a bank account for money that came out
     * of a drawer. Any active cash method will do — installs have exactly one
     * in practice, and the drawer entry is the authoritative record of which
     * till it was.
     */
    private function cashMethodId(): int
    {
        $id = PaymentMethod::query()
            ->where('type', 'cash')
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->orderBy('id')
            ->value('id');

        if (! $id) {
            throw new RuntimeException(__('cash_drawer.errors.no_cash_method'));
        }

        return (int) $id;
    }

    /** Stamp the expense number on the drawer row so the audit reads both ways. */
    private function reasonFor(Expense $expense, string $reason): string
    {
        return trim($expense->number.' — '.$reason);
    }
}
