<?php

namespace App\Actions\Sync;

use App\Actions\Sales\CompleteSale;
use App\Exceptions\DiscountAboveThreshold;
use App\Exceptions\DiscountNotAllowed;
use App\Exceptions\ExpiredBatchSale;
use App\Exceptions\InsufficientStock;
use App\Exceptions\ShiftRequired;
use App\Exceptions\SyncLogNotRetryable;
use App\Http\Requests\Admin\CompleteSaleRequest;
use App\Models\Sale;
use App\Models\SyncLog;
use App\Models\User;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use RuntimeException;

/**
 * Replay a failed / conflicted sync-log row against the server.
 *
 * The whole reason this works is that `sync_logs.payload` stores the
 * ENTIRE original cashier request ({@see App\Http\Controllers\Admin\SaleController::writeSyncLog()}),
 * so nothing has to be reconstructed by hand — we re-validate the same
 * body through the same Form Request and hand it to the same Action the
 * live endpoint uses.
 *
 * Safety:
 *   - `CompleteSale` short-circuits on an existing `sales.local_uuid`,
 *     so pressing Retry twice (or retrying something the terminal has
 *     since drained) can never double-post a sale. The second call
 *     adopts the existing row.
 *   - The replay runs as the ORIGINAL cashier, not the admin pressing
 *     the button, so cashier attribution / shift binding / Z-reports
 *     stay honest. The consequence: when a store enforces shifts and
 *     that cashier's shift is closed, the replay still fails with
 *     "shift required" — by design. The owner reopens a shift for that
 *     cashier (or grants the bypass permission) and retries.
 *
 * The outcome is written back onto the SAME log row — same `local_uuid`,
 * so the log never fans out. A successful retry also stamps
 * `resolved_at` / `resolved_by` so the owner can see who cleared it.
 *
 * Hooks:
 *   - action `sync_log.before_retry` → ($log, $actor)
 *   - action `sync_log.after_retry`  → ($log, $actor)
 */
class RetrySyncLog
{
    public function __construct(private CompleteSale $complete) {}

    public function __invoke(SyncLog $log, User $actor): SyncLog
    {
        $this->assertRetryable($log);

        do_action('sync_log.before_retry', $log, $actor);

        $payload = is_array($log->payload) ? $log->payload : [];

        // Re-validate through the live endpoint's Form Request. A payload
        // that references a since-deleted product / customer / payment
        // method fails HERE with a readable message instead of blowing up
        // deep inside the Action.
        $request = CompleteSaleRequest::create('/cashier/complete', 'POST', $payload);

        try {
            Validator::make($payload, $request->rules())->validate();
        } catch (ValidationException $e) {
            return $this->record($log, $actor, SyncLog::RESULT_FAILED, $this->firstError($e));
        }

        // Attribute to the cashier who rang it up; fall back to the actor
        // only when that user has since been deleted.
        $cashier = $log->user ?: $actor;

        try {
            $sale = ($this->complete)(
                $request->headerData(),
                $request->lineData(),
                $request->paymentData(),
                $cashier,
            );
        } catch (InsufficientStock $e) {
            return $this->record($log, $actor, SyncLog::RESULT_CONFLICT,
                'Insufficient stock for "'.$e->productName.'".');
        } catch (ExpiredBatchSale $e) {
            return $this->record($log, $actor, SyncLog::RESULT_CONFLICT, $e->getMessage());
        } catch (ShiftRequired | DiscountNotAllowed | DiscountAboveThreshold $e) {
            return $this->record($log, $actor, SyncLog::RESULT_FAILED, $e->getMessage());
        } catch (RuntimeException $e) {
            return $this->record($log, $actor, SyncLog::RESULT_FAILED, $e->getMessage());
        }

        return $this->record($log, $actor, SyncLog::RESULT_SUCCESS, null, $sale);
    }

    /**
     * Guard the three cases where a replay makes no sense. Kept separate
     * so the controller can ask the same question to decide whether to
     * render the button ({@see SyncLog::isRetryable()}).
     */
    private function assertRetryable(SyncLog $log): void
    {
        if ($log->isSuccess()) {
            throw new SyncLogNotRetryable('already_synced',
                'This entry already synced successfully — there is nothing to retry.');
        }

        if ($log->isResolved()) {
            throw new SyncLogNotRetryable('resolved',
                'This entry was dismissed. Un-dismiss it before retrying.');
        }

        if ($log->entity !== 'sale') {
            throw new SyncLogNotRetryable('unsupported_entity',
                "Retry only supports sale entries; this one is '{$log->entity}'.");
        }
    }

    /** Write the outcome back onto the same log row. */
    private function record(
        SyncLog $log,
        User $actor,
        string $result,
        ?string $message,
        ?Sale $sale = null,
    ): SyncLog {
        $log->forceFill([
            'result'           => $result,
            'result_message'   => $message,
            'synced_entity_id' => $sale?->id ?? $log->synced_entity_id,
            'synced_at'        => now(),
        ]);

        // Only a success clears the row — a failed retry stays open so it
        // keeps showing up on the Failed tab until someone deals with it.
        if ($result === SyncLog::RESULT_SUCCESS) {
            $log->forceFill([
                'resolved_at' => now(),
                'resolved_by' => $actor->id,
            ]);
        }

        $log->save();

        do_action('sync_log.after_retry', $log, $actor);

        return $log;
    }

    /** Flatten a ValidationException down to one readable line. */
    private function firstError(ValidationException $e): string
    {
        $errors = $e->validator->errors();
        $key    = $errors->keys()[0] ?? null;

        return $key
            ? $key.': '.$errors->first($key)
            : 'The stored payload is no longer valid.';
    }
}
