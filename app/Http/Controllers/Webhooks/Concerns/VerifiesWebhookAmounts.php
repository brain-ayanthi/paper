<?php

namespace App\Http\Controllers\Webhooks\Concerns;

use App\Models\PosPaymentSession;
use App\Support\CurrencyMinorUnits;
use Illuminate\Support\Facades\Log;

/**
 * Amount/currency check before a webhook may mark a session paid —
 * code-review #8.
 *
 * Signatures are verified upstream, so this is defense-in-depth: a SIGNED
 * event can still carry the wrong amount (a partial Razorpay link payment,
 * a zero-amount event, a leaked secret, Flutterwave's static-secret auth).
 * Without this check, every controller flipped the session to PAID on the
 * event type alone — goods released for a short or zero payment.
 *
 * Fail closed: an event that doesn't carry an amount doesn't get to mark a
 * session paid (every provider's success event does carry one; the cashier
 * polling path remains the fallback signal). Currency is compared only when
 * the event surfaces it.
 *
 * State gate (code-review #9): only a live pending|selected session may be
 * marked paid. Gating on `!isPaid()` let a later legitimately-signed event
 * resurrect a cashier-cancelled, TTL-expired, or failed session — silently
 * reopening a voided sale. Terminal non-paid hits are logged so the
 * operator can reconcile the money the shopper actually sent.
 */
trait VerifiesWebhookAmounts
{
    /**
     * The one gate controllers call before flipping a session to PAID:
     * state first, then amount + currency.
     */
    private function webhookMayMarkPaid(array $event, PosPaymentSession $session, string $provider): bool
    {
        if ($session->isPaid()) {
            return false; // duplicate delivery — normal, nothing to log
        }

        if (! $session->isPending()) {
            Log::warning('Webhook for a terminal session ignored — needs reconciliation.', [
                'provider'   => $provider,
                'session_id' => $session->id,
                'status'     => $session->status,
                'payment_id' => $event['payment_id'] ?? null,
            ]);

            return false;
        }

        return $this->webhookEventMatchesSession($event, $session, $provider);
    }

    private function webhookEventMatchesSession(array $event, PosPaymentSession $session, string $provider): bool
    {
        $eventMinor = $event['amount_minor'] ?? null;

        $expected = CurrencyMinorUnits::toMinor(
            (string) $session->amount,
            (string) $session->currency,
        );

        if ($eventMinor === null || $eventMinor === '' || \bccomp((string) $eventMinor, $expected, 0) !== 0) {
            Log::warning('Webhook amount mismatch — session NOT marked paid.', [
                'provider'     => $provider,
                'session_id'   => $session->id,
                'expected'     => $expected,
                'event_amount' => $eventMinor,
            ]);

            return false;
        }

        $eventCurrency = $event['currency'] ?? null;

        if (\is_string($eventCurrency) && $eventCurrency !== ''
            && \strcasecmp($eventCurrency, (string) $session->currency) !== 0) {
            Log::warning('Webhook currency mismatch — session NOT marked paid.', [
                'provider'       => $provider,
                'session_id'     => $session->id,
                'expected'       => (string) $session->currency,
                'event_currency' => $eventCurrency,
            ]);

            return false;
        }

        return true;
    }
}
