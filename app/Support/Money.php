<?php

namespace App\Support;

/**
 * THE money-rounding helper (code-review #31). Half-up (away from zero)
 * at 4dp is the arithmetic backbone of every total in the system —
 * sales, purchases, tax, refunds, stock valuation — and it was
 * copy-pasted as a private helper in five money-critical classes
 * (TaxResolver, CompleteSale, PriceCart, ComputePurchaseTotals,
 * RecordStockMovement). One copy drifting is a silent money bug, so
 * they all delegate here now.
 *
 * Convention (matches PHP_ROUND_HALF_UP — the financial standard):
 *   0.00005 → 0.0001    -0.00005 → -0.0001
 *
 * NOT banker's rounding and NOT half-toward-+inf. If a future flow
 * needs a different mode, add a separate method rather than a flag.
 *
 * Mechanics: nudge the absolute value by half a unit-in-last-place,
 * then truncate to the target scale; the sign is re-applied at the end
 * so negatives round away from zero. Safe for DECIMAL(15,4) values —
 * bcmath never overflows at these magnitudes.
 */
class Money
{
    /**
     * Normalize an incoming amount string to the canonical 4dp form
     * WITHOUT rounding — extra decimals are truncated by bcadd's scale
     * (code-review #41; the former per-action fmt() copies). Distinct
     * from {@see round}: use round() when computing money, normalize()
     * when ingesting request input.
     */
    public static function normalize(string|int|float $v, int $scale = 4): string
    {
        $s = (string) $v;

        if ($s === '' || $s === '-' || $s === '.') {
            return bcadd('0', '0', $scale);
        }

        return bcadd($s, '0', $scale);
    }

    public static function round(string $v, int $scale = 4): string
    {
        // A blank/degenerate string would make bcmath throw; every prior
        // copy either guarded this or never received one — keep the guard.
        if ($v === '' || $v === '-' || $v === '.') {
            return bcadd('0', '0', $scale);
        }

        $sign   = bccomp($v, '0', $scale + 8) < 0 ? '-' : '';
        $abs    = ltrim($v, '-');
        $half   = '0.'.str_repeat('0', $scale).'5';
        $nudged = bcadd($abs, $half, $scale + 4);

        return $sign.bcadd($nudged, '0', $scale);
    }
}
