<?php

namespace App\Services\Commerce;

use App\Models\CommerceConnection;

final class CommerceWebhookVerifier
{
    /** @param array<string, mixed> $credentials */
    public function verify(string $provider, array $credentials, string $payload, ?string $signature): bool
    {
        if ($signature === null || trim($signature) === '') {
            return false;
        }

        $secret = match ($provider) {
            CommerceConnection::PROVIDER_SHOPIFY => (string) ($credentials['client_secret'] ?? ''),
            CommerceConnection::PROVIDER_WOOCOMMERCE => (string) ($credentials['webhook_secret'] ?? ''),
            default => '',
        };

        if ($secret === '') {
            return false;
        }

        $expected = base64_encode(hash_hmac('sha256', $payload, $secret, true));

        return hash_equals($expected, trim($signature));
    }
}
