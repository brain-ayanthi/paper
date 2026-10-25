<?php

namespace App\Services\Payments\Stripe;

use App\Models\PaymentMethod;
use App\Models\Sale;
use App\Models\SalePayment;
use App\Services\Payments\PaymentGateway;
use Illuminate\Http\Request;
use RuntimeException;

/**
 * Stripe implementation of the `PaymentGateway` contract.
 *
 * Per-method credentials live on `payment_methods.provider_credentials`
 * (JSON column the schema already has). One Stripe-typed payment
 * method per company is typical, with `provider_credentials` holding:
 *
 *   {
 *     "mode":              "test" | "live",
 *     "publishable_key":   "pk_test_...",
 *     "secret_key":        "sk_test_...",
 *     "webhook_secret":    "whsec_..."
 *   }
 *
 * The credentials are pulled from the PaymentMethod row passed into
 * each method via `$context['payment_method']`. This way one install
 * can theoretically host multiple Stripe accounts (e.g. one per store
 * via the per-store override table) without code changes.
 */
class StripeGateway implements PaymentGateway
{
    public function __construct(
        private readonly StripeSignatureVerifier $verifier,
    ) {}

    public function code(): string  { return 'stripe'; }
    public function label(): string { return __('sales.payment_methods.stripe'); }

    /* ── Sync-tender stubs (not used for Stripe) ──────────────── */
    public function createOrder(string $amount, Sale $sale): array { return []; }
    public function verify(SalePayment $payment): bool             { return true; }

    public function refund(SalePayment $payment, string $amount): bool
    {
        $client = $this->clientFor($payment->paymentMethod);
        $reference = (string) $payment->gateway_payment_id;
        if ($reference === '') {
            throw new RuntimeException('Cannot refund Stripe payment: missing gateway reference on the payment.');
        }

        // Stripe refunds a PaymentIntent (pi_…), but we may have stored the
        // Checkout Session id (cs_…) if the sale completed before the webhook
        // resolved the intent. Resolve it to the underlying PaymentIntent.
        if (\str_starts_with($reference, 'cs_')) {
            $session   = $client->retrieveCheckoutSession($reference);
            $reference = (string) ($session['payment_intent'] ?? '');
            if ($reference === '') {
                throw new RuntimeException('Cannot refund Stripe payment: the checkout session has no payment intent yet.');
            }
        }

        $amountMinor = $this->toMinor($amount, $payment->currency_code ?? 'usd');
        $client->createRefund($reference, $amountMinor);

        return true;
    }

    /* ── Async lifecycle ──────────────────────────────────────── */

    public function startPayment(string $amountMinor, string $currency, array $context): array
    {
        /** @var PaymentMethod $method */
        $method   = $context['payment_method'];
        $client   = $this->clientFor($method);

        // The cashier always mints a local_uuid per cart — we stash it
        // as Stripe metadata so the webhook can correlate the
        // session back to the originating cart.
        $localUuid = (string) ($context['local_uuid'] ?? '');
        if ($localUuid === '') {
            throw new RuntimeException('startPayment requires `local_uuid` in context.');
        }

        $session = $client->createCheckoutSession(
            amountMinor: (int) $amountMinor,
            currency:    $currency,
            productName: $context['description'] ?? __('sales.payment_methods.stripe_default_line'),
            successUrl:  (string) ($context['return_url'] ?? url('/cashier?stripe_return=success&session={CHECKOUT_SESSION_ID}')),
            cancelUrl:   (string) ($context['return_url'] ?? url('/cashier?stripe_return=cancel&session={CHECKOUT_SESSION_ID}')),
            metadata:    ['local_uuid' => $localUuid],
        );

        return [
            'session_id' => (string) ($session['id']  ?? ''),
            'url'        => (string) ($session['url'] ?? ''),
            'expires_at' => isset($session['expires_at'])
                ? \date('c', (int) $session['expires_at'])
                : null,
        ];
    }

    public function pollStatus(string $sessionId, PaymentMethod $method): array
    {
        $client  = $this->clientFor($method);
        $session = $client->retrieveCheckoutSession($sessionId);

        // Stripe session.payment_status ∈ {paid, unpaid, no_payment_required}.
        // Session.status ∈ {open, complete, expired}.
        // Map both to our normalized vocab.
        $payStatus     = (string) ($session['payment_status'] ?? 'unpaid');
        $sessionStatus = (string) ($session['status']         ?? 'open');

        $status = match (true) {
            $payStatus === 'paid'         => 'paid',
            $sessionStatus === 'expired'  => 'failed',
            $sessionStatus === 'complete' => 'paid', // covers `no_payment_required`
            default                       => 'pending',
        };

        return [
            'status'        => $status,
            'payment_id'    => (string) ($session['payment_intent'] ?? '') ?: null,
            'amount_minor'  => isset($session['amount_total'])
                ? (string) $session['amount_total']
                : null,
        ];
    }

    public function handleWebhook(Request $request, PaymentMethod $method): array
    {
        $creds         = $this->credentials($method);
        $signingSecret = (string) ($creds['webhook_secret'] ?? '');
        $signature     = (string) $request->header('Stripe-Signature', '');
        $rawBody       = (string) $request->getContent();

        $this->verifier->verify($rawBody, $signature, $signingSecret);

        $event = \json_decode($rawBody, true) ?: [];
        $type  = (string) ($event['type'] ?? '');
        $obj   = $event['data']['object'] ?? [];

        return [
            'event_id'     => (string) ($event['id'] ?? ''),
            'event_type'   => $type,
            'session_id'   => (string) ($obj['id'] ?? '') ?: null,
            'payment_id'   => (string) ($obj['payment_intent'] ?? '') ?: null,
            'status'       => (string) ($obj['payment_status'] ?? $obj['status'] ?? '') ?: null,
            'amount_minor' => isset($obj['amount_total'])
                ? (string) $obj['amount_total']
                : null,
            'currency'     => (string) ($obj['currency'] ?? '') ?: null,
            'raw'          => $event,
        ];
    }

    /* ── Helpers ──────────────────────────────────────────────── */

    /** Build a `StripeClient` from the payment method's stored credentials. */
    private function clientFor(?PaymentMethod $method): StripeClient
    {
        if (!$method) {
            throw new RuntimeException('Stripe gateway: no payment method context.');
        }
        $creds  = $this->credentials($method);
        $secret = (string) ($creds['secret_key'] ?? '');
        return new StripeClient($secret);
    }

    /** Returns the decoded provider_credentials JSON, or []. */
    private function credentials(PaymentMethod $method): array
    {
        $raw = $method->provider_credentials;
        if (\is_array($raw))  return $raw;
        if (\is_string($raw)) return \json_decode($raw, true) ?: [];
        return [];
    }

    /**
     * Convert a decimal-string amount to Stripe's minor unit
     * (integer cents/paise/etc.). Decimal places depend on currency;
     * USD/EUR = 2, JPY = 0, KWD = 3, etc. — Stripe documents the
     * list at https://docs.stripe.com/currencies#zero-decimal but for
     * v1 we assume 2 decimals which covers ~95% of currencies. Zero-
     * decimal currencies are a polish-slice follow-up.
     */
    private function toMinor(string $amount, string $currency): int
    {
        $decimals = $this->decimalsFor($currency);
        $scaled   = \bcmul($amount, \bcpow('10', (string) $decimals, 0), 0);
        return (int) $scaled;
    }

    private function decimalsFor(string $currency): int
    {
        $zero  = ['JPY', 'KRW', 'VND', 'CLP', 'IDR'];
        $three = ['BHD', 'JOD', 'KWD', 'OMR', 'TND'];
        $upper = \strtoupper($currency);
        if (\in_array($upper, $zero,  true)) return 0;
        if (\in_array($upper, $three, true)) return 3;
        return 2;
    }
}
