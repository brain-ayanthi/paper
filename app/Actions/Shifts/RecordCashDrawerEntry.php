<?php

namespace App\Actions\Shifts;

use App\Exceptions\InsufficientDrawerCash;
use App\Exceptions\ShiftNotOpen;
use App\Models\CashDrawerEntry;
use App\Models\Shift;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Record a cash-drawer movement against an open shift — pay-in,
 * pay-out, or drawer-open-no-sale. The Z/X-report math (see
 * {@see ComputeShiftTotals}) already aggregates `cash_drawer_entries`
 * for the shift, so once this writes the row the expected-cash figure
 * shifts on the next render.
 *
 * Type semantics:
 *   - `pay_in`               → amount > 0, expected cash GOES UP
 *   - `pay_out`              → amount > 0, expected cash GOES DOWN
 *   - `drawer_open_no_sale`  → amount ignored (stored as 0), audit-only;
 *                              counts on the Z-report under "Drawer
 *                              opens (no sale)" but doesn't move money.
 *
 * A pay-out can never exceed what the drawer holds — see
 * {@see guardSufficientCash()}. Every pay-out path in the app funnels through
 * here (the shift panel, the cashier's cash in/out modal, a cash supplier
 * payment, a cash expense), so this one check is the whole rule.
 *
 * Hooks:
 *   - action `cash_drawer.pay_in_recorded`     → ($entry, $shift)
 *   - action `cash_drawer.pay_out_recorded`    → ($entry, $shift)
 *   - action `cash_drawer.opened_no_sale`      → ($entry, $shift)
 */
class RecordCashDrawerEntry
{
    public function __construct(private readonly AvailableDrawerCash $available) {}

    /**
     * @param array<string, mixed> $data {
     *   type: 'pay_in'|'pay_out'|'drawer_open_no_sale',
     *   category?: string,                   // pay_out only — see §4.3
     *   amount?: numeric-string|float|null,  // required for pay_in / pay_out
     *   reason: string,
     *   expense_id?: ?int,                   // pay_out optional link
     * }
     */
    public function __invoke(Shift $shift, array $data, User $user): CashDrawerEntry
    {
        if (! $shift->isOpen()) {
            throw new ShiftNotOpen($shift);
        }

        $type = (string) ($data['type'] ?? '');
        if (! in_array($type, [
            CashDrawerEntry::TYPE_PAY_IN,
            CashDrawerEntry::TYPE_PAY_OUT,
            CashDrawerEntry::TYPE_DRAWER_OPEN_NO_SALE,
        ], true)) {
            throw new InvalidArgumentException("Unknown cash-drawer entry type: {$type}");
        }

        $amount = $type === CashDrawerEntry::TYPE_DRAWER_OPEN_NO_SALE
            ? '0.0000'
            : $this->fmt((string) ($data['amount'] ?? '0'));

        if ($type !== CashDrawerEntry::TYPE_DRAWER_OPEN_NO_SALE
            && bccomp($amount, '0', 4) <= 0
        ) {
            throw new InvalidArgumentException(__('cash_drawer.errors.amount_must_be_positive'));
        }

        return DB::transaction(function () use ($shift, $type, $amount, $data, $user) {
            // Inside the transaction so a pay-out is measured against the
            // drawer as it stands at write time, not as it stood when the
            // form was rendered.
            if ($type === CashDrawerEntry::TYPE_PAY_OUT) {
                $this->guardSufficientCash($shift, $amount);
            }

            $entry = CashDrawerEntry::create([
                'shift_id'              => (int) $shift->id,
                'type'                  => $type,
                // Pay-outs only (§4.3) — pay-ins / no-sale opens have no
                // category, so an unknown value is stored as NULL rather than
                // labelling the row with something the UI can't render.
                'category'              => $type === CashDrawerEntry::TYPE_PAY_OUT
                    ? $this->category($data)
                    : null,
                'amount'                => $amount,
                'reason'                => (string) ($data['reason'] ?? ''),
                'expense_id'            => $data['expense_id'] ?? null,
                'supplier_payment_uuid' => $data['supplier_payment_uuid'] ?? null,
                'created_by'            => (int) $user->id,
            ]);

            $hook = match ($type) {
                CashDrawerEntry::TYPE_PAY_IN              => 'cash_drawer.pay_in_recorded',
                CashDrawerEntry::TYPE_PAY_OUT             => 'cash_drawer.pay_out_recorded',
                CashDrawerEntry::TYPE_DRAWER_OPEN_NO_SALE => 'cash_drawer.opened_no_sale',
            };
            do_action($hook, $entry, $shift);

            return $entry->fresh();
        });
    }

    /**
     * A drawer can't hand out money it doesn't hold. The ceiling is the
     * shift's expected cash, so a pay-out is bounded by opening float plus
     * cash actually taken in — not by the shift's total turnover, which
     * includes card and UPI money that never entered the till.
     *
     * Deliberately hard: there's no permission or setting that lets a pay-out
     * through. Overdrawing isn't a policy the store can choose — it produces a
     * Z-report whose expected cash cannot exist, and every real correction
     * (float top-up from the safe) has a pay-in that fixes it properly.
     */
    private function guardSufficientCash(Shift $shift, string $amount): void
    {
        $available = ($this->available)($shift);

        if (bccomp($amount, $available, 4) > 0) {
            throw new InsufficientDrawerCash($shift, $amount, $available);
        }
    }

    /**
     * Normalise the pay-out reason category. An unrecognised value (or none)
     * falls back to `other` so the row is still classifiable — the column is
     * only left NULL for entry types that have no categories at all.
     *
     * @param array<string, mixed> $data
     */
    private function category(array $data): string
    {
        $category = (string) ($data['category'] ?? '');

        return in_array($category, CashDrawerEntry::payOutCategories(), true)
            ? $category
            : CashDrawerEntry::CATEGORY_OTHER;
    }

    private function fmt(string $v): string
    {
        return \App\Support\Money::normalize($v);
    }
}
