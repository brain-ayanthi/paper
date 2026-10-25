<?php

namespace App\Http\Middleware;

use App\Services\TerminalReservation;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class ReserveCashierTerminal
{
    public function handle(Request $request, Closure $next)
    {
        if ($request->user() && $request->routeIs('cashier.*')
            && ! $request->routeIs('cashier.terminal.select', 'cashier.display*')) {
            $terminal = current_terminal();
            if ($terminal) {
                try {
                    app(TerminalReservation::class)->claim((int) $terminal->id, $request);
                } catch (ValidationException $e) {
                    if (! $request->routeIs('cashier.index')) {
                        throw $e;
                    }
                    // Render the picker again when an old cookie belongs to a busy till.
                    $request->cookies->remove('pos_terminal_id');
                    app()->forgetInstance('pos.current_terminal');
                    cookie()->queue(cookie()->forget('pos_terminal_id'));
                }
            }
        }

        return $next($request);
    }
}
