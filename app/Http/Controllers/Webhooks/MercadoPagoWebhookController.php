<?php

namespace App\Http\Controllers\Webhooks;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Webhooks\Concerns\DedupesWebhookEvents;
use App\Http\Controllers\Webhooks\Concerns\VerifiesWebhookAmounts;
use App\Models\PaymentMethod;
use App\Models\PosPaymentSession;
use App\Services\Payments\MercadoPago\MercadoPagoGateway;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Mercado Pago webhook receiver — `POST /webhooks/mercado_pago`.
 *
 * Public route (no auth, no CSRF — see VerifyCsrfToken middleware
 * `$except` list). Mercado Pago authenticates webhooks via a signed
 * manifest (id + request-id + ts HMAC'd with the merchant's
 * webhook_secret) — see MercadoPagoSignatureVerifier.
 *
 * Mirrors the Stripe/Razorpay/Paystack/Flutterwave controllers in
 * shape, with one twist: Mercado Pago's notifications only carry the
 * payment id, so the gateway's `handleWebhook` does an extra fetch
 * to resolve the payment status before we can mark a session paid.
 * The PosPaymentSession is correlated by `external_reference`
 * (which we set to the session uuid in `startPayment`).
 */
class MercadoPagoWebhookController extends Controller
{
    use DedupesWebhookEvents;
    use VerifiesWebhookAmounts;

    public function __invoke(Request $request, MercadoPagoGateway $gateway): JsonResponse
    {
        $method = PaymentMethod::query()
            ->where('provider', 'mercado_pago')
            ->where('is_active', true)
            ->first();

        if (!$method) {
            return response()->json(['error' => 'Mercado Pago not configured.'], 400);
        }

        try {
            $event = $gateway->handleWebhook($request, $method);
        } catch (RuntimeException $e) {
            return response()->json(['error' => $e->getMessage()], 400);
        }

        // Claim the event id before acting on it — a replayed
        // delivery is logged over the top otherwise and the handler
        // below runs again (SEC #65).
        $fresh = $this->claimWebhookEvent(
            'mercado_pago',
            (string) $event['event_id'],
            (string) $event['event_type'],
            (array) $event['raw'],
        );

        if (! $fresh) {
            return response()->json(['received' => true, 'duplicate' => true]);
        }

        // Mark the related PosPaymentSession paid only when the
        // resolved payment is `approved`. session_id ← Mercado Pago
        // external_reference (= our session uuid). The cashier
        // polling will also see this when its 2s tick lands.
        if (
            ($event['status'] ?? null) === 'approved'
            && !empty($event['session_id'])
        ) {
            $session = PosPaymentSession::query()
                ->where('gateway_session_id', $event['session_id'])
                ->first();
            // Signed ≠ correct: only a live pending|selected session whose
            // amount and currency match the event may flip to paid
            // (code-review #8 + #9 — no resurrecting cancelled/expired).
            if ($session && $this->webhookMayMarkPaid($event, $session, 'mercado_pago')) {
                $session->forceFill([
                    'status'             => PosPaymentSession::STATUS_PAID,
                    'gateway_payment_id' => $event['payment_id'] ?? $session->gateway_payment_id,
                    'paid_at'            => now(),
                ])->save();
            }
        }

        return response()->json(['received' => true]);
    }
}
