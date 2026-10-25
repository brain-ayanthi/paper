<?php

namespace App\Exceptions;

use App\Models\Shift;
use RuntimeException;

/**
 * A pay-out would take more cash out of the drawer than the drawer holds.
 *
 * "Holds" is the shift's expected cash — `opening + cash sales − cash refunds
 * + pay-ins − pay-outs` ({@see App\Actions\Shifts\ComputeShiftTotals}). A
 * drawer physically cannot go negative, so allowing it produced a shift that
 * expected impossible cash and a Z-report nobody could reconcile: the cashier
 * counts what's actually there and the variance is unexplainable.
 *
 * Extends RuntimeException on purpose — the three surfaces that can trigger it
 * ({@see App\Http\Controllers\Admin\ShiftController::recordCashDrawerEntry},
 * {@see App\Http\Controllers\Admin\SupplierPaymentController::store},
 * {@see App\Http\Controllers\Admin\ExpenseController}) already convert a
 * RuntimeException into a 422 + toast, so it surfaces as a normal form error
 * rather than a 500.
 */
class InsufficientDrawerCash extends RuntimeException
{
    public function __construct(
        public readonly Shift $shift,
        public readonly string $requested,
        public readonly string $available,
    ) {
        $currency = $shift->store?->currency_code;

        parent::__construct(__('cash_drawer.errors.insufficient_cash', [
            'requested' => format_money($requested, null, $currency),
            'available' => format_money($available, null, $currency),
        ]));
    }
}
