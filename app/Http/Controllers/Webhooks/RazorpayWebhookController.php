<?php

namespace App\Http\Controllers\Webhooks;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Webhooks\Concerns\DedupesWebhookEvents;
use App\Http\Controllers\Webhooks\Concerns\VerifiesWebhookAmounts;
use App\Models\PaymentMethod;
use App\Models\PosPaymentSession;
use App\Services\Payments\Razorpay\RazorpayGateway;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Razorpay webhook receiver — `POST /webhooks/razorpay`.
 *
 * Public route (no auth, no CSRF — see VerifyCsrfToken middleware
 * `$except` list). Razorpay signs every payload; the
 * RazorpaySignatureVerifier rejects anything that doesn't validate.
 *
 * Mirrors StripeWebhookController in shape — logs every event to
 * `payment_webhook_log` for audit, and marks `PosPaymentSession` paid
 * on `payment_link.paid` as the connection-drop safety net (the
 * cashier polling path is the primary signal).
 */
class RazorpayWebhookController extends Controller
{
    use DedupesWebhookEvents;
    use VerifiesWebhookAmounts;

    public function __invoke(Request $request, RazorpayGateway $gateway): JsonResponse
    {
        $method = PaymentMethod::query()
            ->where('provider', 'razorpay')
            ->where('is_active', true)
            ->first();

        if (!$method) {
            return response()->json(['error' => 'Razorpay not configured.'], 400);
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
            'razorpay',
            (string) $event['event_id'],
            (string) $event['event_type'],
            (array) $event['raw'],
        );

        if (! $fresh) {
            return response()->json(['received' => true, 'duplicate' => true]);
        }

        // Mark the related PosPaymentSession paid when Razorpay
        // confirms a payment_link is paid. The cashier polling will
        // also see this when its 2s tick lands — webhook is the
        // safety net for the connection-drop case.
        if ($event['event_type'] === 'payment_link.paid' && !empty($event['session_id'])) {
            $session = PosPaymentSession::query()
                ->where('gateway_session_id', $event['session_id'])
                ->first();
            // Signed ≠ correct: a partial payment-link payment still fires
            // payment_link.paid — the amount must match, and only a live
            // pending|selected session may flip (code-review #8 + #9).
            if ($session && $this->webhookMayMarkPaid($event, $session, 'razorpay')) {
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
