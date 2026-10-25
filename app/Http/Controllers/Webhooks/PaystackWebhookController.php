<?php

namespace App\Http\Controllers\Webhooks;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Webhooks\Concerns\DedupesWebhookEvents;
use App\Http\Controllers\Webhooks\Concerns\VerifiesWebhookAmounts;
use App\Models\PaymentMethod;
use App\Models\PosPaymentSession;
use App\Services\Payments\Paystack\PaystackGateway;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Paystack webhook receiver — `POST /webhooks/paystack`.
 *
 * Public route (no auth, no CSRF — see VerifyCsrfToken middleware
 * `$except` list). Paystack signs every payload with the merchant's
 * secret_key; the PaystackSignatureVerifier rejects anything that
 * doesn't validate.
 *
 * Mirrors Stripe/Razorpay controllers in shape — logs every event to
 * `payment_webhook_log` for audit, and marks `PosPaymentSession` paid
 * on `charge.success`.
 */
class PaystackWebhookController extends Controller
{
    use DedupesWebhookEvents;
    use VerifiesWebhookAmounts;

    public function __invoke(Request $request, PaystackGateway $gateway): JsonResponse
    {
        $method = PaymentMethod::query()
            ->where('provider', 'paystack')
            ->where('is_active', true)
            ->first();

        if (!$method) {
            return response()->json(['error' => 'Paystack not configured.'], 400);
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
            'paystack',
            (string) $event['event_id'],
            (string) $event['event_type'],
            (array) $event['raw'],
        );

        if (! $fresh) {
            return response()->json(['received' => true, 'duplicate' => true]);
        }

        // Mark the related PosPaymentSession paid when Paystack
        // confirms charge.success. The cashier polling will also
        // see this when its 2s tick lands — webhook is the safety
        // net for the connection-drop case. We correlate by the
        // Paystack reference (which IS our session uuid — see
        // PaystackGateway::startPayment).
        if ($event['event_type'] === 'charge.success' && !empty($event['session_id'])) {
            $session = PosPaymentSession::query()
                ->where('gateway_session_id', $event['session_id'])
                ->first();
            // Signed ≠ correct: only a live pending|selected session whose
            // amount and currency match the event may flip to paid
            // (code-review #8 + #9 — no resurrecting cancelled/expired).
            if ($session && $this->webhookMayMarkPaid($event, $session, 'paystack')) {
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
