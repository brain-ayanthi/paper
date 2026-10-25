<?php

namespace App\Http\Controllers;

use App\Actions\Commerce\ScheduleImmediateCommerceProcessing;
use App\Models\CommerceConnection;
use App\Models\CommerceWebhookEvent;
use App\Services\Commerce\CommerceWebhookVerifier;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CommerceWebhookController extends Controller
{
    public function __invoke(
        Request $request,
        string $provider,
        CommerceConnection $connection,
        CommerceWebhookVerifier $verifier,
        ScheduleImmediateCommerceProcessing $immediate,
    ): JsonResponse {
        abort_unless(hash_equals($connection->provider, $provider), 404);

        $raw = $request->getContent();
        if ($raw === '' || strlen($raw) > 2 * 1024 * 1024) {
            return response()->json(['message' => __('commerce.webhook.invalid_payload')], 422);
        }

        $signature = match ($provider) {
            CommerceConnection::PROVIDER_SHOPIFY => $request->header('X-Shopify-Hmac-Sha256'),
            CommerceConnection::PROVIDER_WOOCOMMERCE => $request->header('X-WC-Webhook-Signature'),
            default => null,
        };

        if (! $verifier->verify($provider, $connection->credentials ?? [], $raw, $signature)) {
            return response()->json(['message' => __('commerce.webhook.invalid_signature')], 401);
        }

        $payload = json_decode($raw, true);
        if (! is_array($payload)) {
            return response()->json(['message' => __('commerce.webhook.invalid_json')], 422);
        }

        $topic = match ($provider) {
            CommerceConnection::PROVIDER_SHOPIFY => (string) $request->header('X-Shopify-Topic', 'unknown'),
            default => (string) $request->header('X-WC-Webhook-Topic', 'unknown'),
        };
        $eventId = match ($provider) {
            CommerceConnection::PROVIDER_SHOPIFY => $request->header('X-Shopify-Event-Id'),
            default => $request->header('X-WC-Webhook-Delivery-ID'),
        };
        $eventId = trim((string) $eventId) ?: hash('sha256', $provider.'|'.$topic.'|'.$raw);

        $event = CommerceWebhookEvent::query()->firstOrCreate(
            [
                'commerce_connection_id' => $connection->getKey(),
                'event_id' => mb_substr($eventId, 0, 191),
            ],
            [
                'provider' => $provider,
                'topic' => mb_substr($topic, 0, 100),
                'payload' => $payload,
                'status' => CommerceWebhookEvent::STATUS_PENDING,
                'available_at' => now('UTC'),
                'received_at' => now('UTC'),
            ],
        );
        if ($event->status === CommerceWebhookEvent::STATUS_PENDING) {
            $immediate->webhooks([(int) $event->getKey()]);
        }

        $connection->forceFill(['last_webhook_at' => now('UTC')])->saveQuietly();

        return response()->json(['accepted' => true], 202);
    }
}
