<?php

namespace App\Actions\Shifts;

use App\Models\Shift;

/**
 * How much cash a shift's drawer can still pay out.
 *
 * This is the shift's expected cash — the same figure the X/Z-report shows —
 * so the cap the server enforces and the number the cashier reads on screen
 * are one value, not two that can drift. Going through
 * {@see ComputeShiftTotals} also means the `shift.expected_cash_calculation`
 * filter applies, so a plugin that injects custom movements moves the cap too.
 */
class AvailableDrawerCash
{
    public function __construct(private readonly ComputeShiftTotals $totals) {}

    /** @return string 4dp decimal-string; can be negative on legacy data. */
    public function __invoke(Shift $shift): string
    {
        return (string) ($this->totals)($shift)['expected_cash'];
    }
}
