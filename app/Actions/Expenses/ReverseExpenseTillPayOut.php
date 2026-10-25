<?php

namespace App\Actions\Expenses;

use App\Models\CashDrawerEntry;
use App\Models\Expense;
use App\Models\User;

/**
 * Put the money back in the till when a cash expense is deleted.
 *
 * Without this, deleting an expense would leave its pay-out standing: the
 * expense disappears from the P&L but the shift still expects less cash, so
 * the cashier ends up long by that amount with nothing to point at.
 *
 * Implemented as "reconcile the drawer to zero for this expense", reusing
 * {@see SyncExpenseTillPayOut::reconcile()} — so it writes a compensating
 * `pay_in` rather than erasing the pay-out, and it stays correct even if the
 * expense was edited a few times first (it credits back the NET, not the
 * original amount).
 *
 * Same frozen-shift rule as the sync: if the shift that paid it has closed,
 * its Z-report is already reconciled and nothing is written.
 */
class ReverseExpenseTillPayOut
{
    public function __construct(private readonly SyncExpenseTillPayOut $sync) {}

    public function __invoke(Expense $expense, ?User $user): ?CashDrawerEntry
    {
        if ($user === null) {
            return null;
        }

        return $this->sync->reconcile($expense, $user, '0');
    }
}
