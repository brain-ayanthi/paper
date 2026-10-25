<?php

namespace App\Http\Controllers\Admin;

use App\Actions\Hardware\PreparePrintPayload;
use App\Actions\Hardware\RecordPrintLog;
use App\Http\Controllers\Controller;
use App\Http\Requests\Hardware\RecordPrintLogRequest;
use App\Models\Sale;
use Illuminate\Http\JsonResponse;

/**
 * Print pipeline endpoints (docs/features/hardware.md §15.1). The client
 * `<x-print-button>` fetches a sale's dual-format payload here, hands it
 * to the print bridge, then reports the outcome back to the log endpoint.
 */
class PrintController extends Controller
{
    /**
     * Dual-format print payload for a sale receipt: HTML for browser
     * print, ESC/POS bytes for WebUSB. Resolved against the current
     * terminal's printer config.
     */
    public function salePayload(Sale $sale, PreparePrintPayload $prepare): JsonResponse
    {
        $this->authorize('view', $sale);

        return response()->json(($prepare)($sale, current_terminal()));
    }

    /** Record a print attempt (success / failed / queued). */
    public function log(RecordPrintLogRequest $request, RecordPrintLog $record): JsonResponse
    {
        $log = ($record)($request->validated());

        return response()->json(['ok' => true, 'id' => $log->id]);
    }
}
