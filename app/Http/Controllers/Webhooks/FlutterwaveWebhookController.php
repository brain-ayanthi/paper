<?php

namespace App\Http\Controllers\Webhooks;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Webhooks\Concerns\DedupesWebhookEvents;
use App\Http\Controllers\Webhooks\Concerns\VerifiesWebhookAmounts;
use App\Models\PaymentMethod;
use App\Models\PosPaymentSession;
use App\Services\Payments\Flutterwave\FlutterwaveGateway;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Flutterwave webhook receiver — `POST /webhooks/flutterwave`.
 *
 * Public route (no auth, no CSRF — see VerifyCsrfToken middleware
 * `$except` list). Flutterwave authenticates webhooks by echoing
 * the merchant's configured "secret hash" string in the `verif-hash`
 * header — see FlutterwaveSignatureVerifier.
 *
 * Mirrors Stripe/Razorpay/Paystack controllers — logs every event
 * to `payment_webhook_log` for audit, and marks `PosPaymentSession`
 * paid on `charge.completed` with status `successful`.
 */
class FlutterwaveWebhookController extends Controller
{
    use DedupesWebhookEvents;
    use VerifiesWebhookAmounts;

    public function __invoke(Request $request, FlutterwaveGateway $gateway): JsonResponse
    {
        $method = PaymentMethod::query()
            ->where('provider', 'flutterwave')
            ->where('is_active', true)
            ->first();

        if (!$method) {
            return response()->json(['error' => 'Flutterwave not configured.'], 400);
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
            'flutterwave',
            (string) $event['event_id'],
            (string) $event['event_type'],
            (array) $event['raw'],
        );

        if (! $fresh) {
            return response()->json(['received' => true, 'duplicate' => true]);
        }

        // Mark the related PosPaymentSession paid when Flutterwave
        // confirms a charge.completed with status `successful`. The
        // cashier polling will also see this when its 2s tick lands.
        // We correlate by tx_ref (our session uuid).
        if (
            $event['event_type'] === 'charge.completed'
            && ($event['status'] ?? null) === 'successful'
            && !empty($event['session_id'])
        ) {
            $session = PosPaymentSession::query()
                ->where('gateway_session_id', $event['session_id'])
                ->first();
            // Flutterwave's static-secret webhook auth is the weakest of the
            // five — the amount check matters most here, and only a live
            // pending|selected session may flip (code-review #8 + #9).
            if ($session && $this->webhookMayMarkPaid($event, $session, 'flutterwave')) {
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
