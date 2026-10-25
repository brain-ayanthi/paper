<?php

namespace App\Http\Controllers\Webhooks\Concerns;

use Illuminate\Support\Facades\DB;

/**
 * Replay protection for gateway webhooks — security register SEC #65.
 *
 * Every receiver logs its event to `payment_webhook_log` (unique on
 * `provider` + `gateway_event_id`) and then acts on it. Logging first is not
 * dedupe: `updateOrInsert` happily overwrites the existing row, so the same
 * captured delivery replayed a hundred times ran the handler a hundred times.
 *
 * That is mostly harmless today — the handlers only flip a session to paid and
 * already guard with `! isPaid()` — but it is harmless by accident. Flutterwave
 * in particular authenticates with a STATIC shared string echoed in
 * `verif-hash` (no HMAC, no timestamp, nothing per-request), so one captured
 * request stays valid forever; Razorpay and Paystack sign the body but carry no
 * timestamp tolerance, so a captured delivery is likewise replayable. The first
 * webhook-driven state change that isn't idempotent turns that into a real bug.
 *
 * So: claim the event id BEFORE acting. The unique index is the actual lock —
 * an insert that violates it means somebody else already has this event, and we
 * stop.
 */
trait DedupesWebhookEvents
{
    /**
     * Try to claim this event. True on the first delivery, false on a replay
     * or a concurrent duplicate.
     *
     * @param  array<string, mixed>  $raw
     */
    protected function claimWebhookEvent(
        string $provider,
        string $eventId,
        string $eventType,
        array $raw,
    ): bool {
        // No usable id — every gateway we ship synthesises one from a body
        // hash when the payload has no explicit event id, so this is the
        // "can't tell deliveries apart" case. Let it through rather than
        // silently dropping a real payment confirmation.
        if ($eventId === '') {
            return true;
        }

        try {
            DB::table('payment_webhook_log')->insert([
                'provider'         => $provider,
                'gateway_event_id' => $eventId,
                'event_type'       => $eventType,
                'payload'          => json_encode($raw),
                'processed'        => true,
                'result'           => 'logged',
                'received_at'      => now(),
                'processed_at'     => now(),
            ]);
        } catch (\Illuminate\Database\UniqueConstraintViolationException) {
            return false;
        } catch (\Illuminate\Database\QueryException $e) {
            // Older Laravel / driver combinations surface the same thing as a
            // generic QueryException. 23000 is the SQLSTATE integrity-violation
            // class, which is what a duplicate key raises on both MySQL and
            // SQLite.
            if ((string) $e->getCode() !== '23000') {
                throw $e;
            }

            return false;
        }

        return true;
    }
}
