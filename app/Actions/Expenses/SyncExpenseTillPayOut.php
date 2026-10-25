<?php

namespace App\Actions\Expenses;

use App\Actions\Shifts\RecordCashDrawerEntry;
use App\Models\CashDrawerEntry;
use App\Models\Expense;
use App\Models\Shift;
use App\Models\User;

/**
 * Keep a cash expense and the till it was paid from in step.
 *
 * The bug this closes: an expense recorded in the back office wrote nothing to
 * `cash_drawer_entries`, and a shift's expected cash is
 * `opening + cash sales − cash refunds + pay-ins − pay-outs`
 * ({@see App\Actions\Shifts\ComputeShiftTotals}). So cash physically left the
 * drawer while the X/Z report still expected it to be there, and the cashier
 * counted short by exactly the expense amount at close.
 *
 * ── How it stays correct across edits ──────────────────────────────────
 * Rather than mutating the original pay-out, this RECONCILES: it compares the
 * drawer's current net effect for this expense (pay-outs minus pay-ins, both
 * carrying `expense_id`) against what the expense now says should have left
 * the till, and writes one correcting entry for the difference. That single
 * rule covers every case without special-casing any of them:
 *
 *   - first save                → net 0 → target 250 → pay_out 250
 *   - amount edited up to 300   → net 250 → target 300 → pay_out 50
 *   - amount edited down to 100 → net 300 → target 100 → pay_in 200
 *   - switched to bank transfer → net 100 → target 0   → pay_in 100
 *   - switched back to cash     → net 0   → target 100 → pay_out 100
 *
 * It's therefore idempotent — calling it twice with nothing changed writes
 * nothing — and the drawer keeps a full trail instead of a silently rewritten
 * amount.
 *
 * ── When it does nothing, and why ──────────────────────────────────────
 *   - no acting user (seeder / scheduled recurring expense) — there is no
 *     till session to attribute the movement to
 *   - the expense never touched a till and isn't cash now — nothing to do
 *   - no open shift to book against. For a NEW cash expense that means the
 *     money came from an office float or safe rather than a till, mirroring
 *     the rule {@see App\Actions\Purchases\RecordSupplierPayment} uses for
 *     paying a supplier from the till.
 *   - the shift that paid it has CLOSED — its Z-report is frozen and already
 *     reconciled against counted cash. Rewriting it would invent a variance
 *     nobody can explain, so a later edit simply doesn't reach the drawer.
 */
class SyncExpenseTillPayOut
{
    public function __construct(private readonly RecordCashDrawerEntry $recordEntry) {}

    public function __invoke(Expense $expense, ?User $user): ?CashDrawerEntry
    {
        if ($user === null) {
            return null;
        }

        return $this->reconcile(
            $expense,
            $user,
            $this->isCash($expense) ? (string) $expense->total : '0',
        );
    }

    /**
     * Move the drawer to `$target` for this expense, or do nothing if it's
     * already there. Shared with {@see ReverseExpenseTillPayOut}, which
     * reconciles to zero.
     */
    public function reconcile(Expense $expense, User $user, string $target): ?CashDrawerEntry
    {
        $entries = CashDrawerEntry::query()
            ->with('shift')
            ->where('expense_id', $expense->id)
            ->get();

        $net = '0';
        foreach ($entries as $entry) {
            $net = $entry->type === CashDrawerEntry::TYPE_PAY_OUT
                ? bcadd($net, (string) $entry->amount, 4)
                : bcsub($net, (string) $entry->amount, 4);
        }

        $delta = bcsub($target, $net, 4);
        if (bccomp($delta, '0', 4) === 0) {
            return null;
        }

        $shift = $this->shiftFor($expense, $user, $entries);
        if (! $shift) {
            return null;
        }

        $isPayOut = bccomp($delta, '0', 4) > 0;

        return ($this->recordEntry)($shift, [
            'type'       => $isPayOut ? CashDrawerEntry::TYPE_PAY_OUT : CashDrawerEntry::TYPE_PAY_IN,
            'category'   => CashDrawerEntry::CATEGORY_EXPENSE,
            // The engine only accepts positive amounts; direction is the type.
            'amount'     => $isPayOut ? $delta : bcmul($delta, '-1', 4),
            'reason'     => $this->reasonFor($expense, $isPayOut),
            'expense_id' => (int) $expense->id,
        ], $user);
    }

    /**
     * Which drawer to correct. Money goes back where it came from: if this
     * expense already moved a till, that same shift — crediting a different
     * one would just relocate the discrepancy. Only a first-time movement
     * falls back to the acting user's own open shift.
     *
     * @param \Illuminate\Support\Collection<int, CashDrawerEntry> $entries
     */
    private function shiftFor(Expense $expense, User $user, $entries): ?Shift
    {
        $existing = $entries->first()?->shift;
        if ($existing) {
            return $existing->isOpen() ? $existing : null;
        }

        return Shift::openForCashier((int) $expense->store_id, (int) $user->id);
    }

    /** Only a cash-type payment method empties a physical drawer. */
    private function isCash(Expense $expense): bool
    {
        $expense->loadMissing('paymentMethod');

        return $expense->paymentMethod?->type === 'cash';
    }

    private function reasonFor(Expense $expense, bool $isPayOut): string
    {
        $expense->loadMissing('category');

        return $isPayOut
            ? __('expenses.till.pay_out_reason', [
                'number'   => $expense->number,
                'category' => $expense->category?->name ?: __('expenses.till.uncategorised'),
            ])
            : __('expenses.till.correction_reason', ['number' => $expense->number]);
    }
}
