<?php

namespace App\Http\Controllers\Cashier;

use App\Actions\Shifts\OpenShift;
use App\Exceptions\ShiftAlreadyOpen;
use App\Exceptions\TerminalShiftOpen;
use App\Http\Controllers\Concerns\RespondsJsonOrRedirect;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\OpenShiftRequest;
use App\Models\Store;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;

/**
 * Cashier-surface shift opening (Slice A — shift enforcement).
 *
 * Lets the operator open a shift WITHOUT leaving /cashier when the store
 * enforces shifts. The shift gate (resources/js/cashier/shift-gate.js)
 * POSTs here, then reloads so the cashier boots with the shift bound and
 * {@see \App\Actions\Sales\CompleteSale} auto-binds `sales.shift_id`.
 *
 * Reuses the same OpenShift action + OpenShiftRequest as the admin
 * surface; only the redirect target differs (back to /cashier).
 */
class ShiftController extends Controller
{
    use RespondsJsonOrRedirect;

    public function open(OpenShiftRequest $request, OpenShift $open): JsonResponse|RedirectResponse
    {
        $user    = $request->user();
        $storeId = active_store_id();
        $store   = Store::query()->findOrFail($storeId);

        // Use the new-shift picker, falling back to the current browser selection.
        $data = $request->validated() + ['terminal_id' => current_terminal_id()];
        if (! empty($data['terminal_id'])) {
            app(\App\Services\TerminalReservation::class)->claim((int) $data['terminal_id'], $request);
        }

        try {
            $shift = $open($data, $user, $store);
        } catch (ShiftAlreadyOpen $e) {
            // Already-open is a no-op success for the gate — the cashier
            // just needs *a* shift; surface it and let the screen reload.
            return $this->jsonOrRedirect(
                $request,
                __('shifts.flash.opened', ['number' => '#'.$e->existing->id]),
                route('cashier.index'),
            );
        } catch (TerminalShiftOpen $e) {
            // Another cashier holds this till's drawer — block with a
            // clear message instead of opening a parallel shift.
            return $this->jsonOrError($request, $e->getMessage());
        }

        if ($shift->terminal_id) {
            cookie()->queue(cookie()->forever('pos_terminal_id', (string) $shift->terminal_id));
        }

        return $this->jsonOrRedirect(
            $request,
            __('shifts.flash.opened', ['number' => '#'.$shift->id]),
            route('cashier.index'),
        );
    }
}
