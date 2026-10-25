<?php

namespace App\Services\Licensing;

use Illuminate\Support\Facades\Http;

/** Adapts the validator v1 API to the installer and optional background re-check. */
class LicenseClient
{
    /**
     * Validation binds the domain; repeating it for the same domain uses no extra seat.
     * Transport/configuration failures remain distinct from rejected purchase codes.
     *
     * @param  array<string, mixed>  $payload
     * @return array{ok: bool, body?: array<string, mixed>, status?: int, reason?: string, error?: string}
     */
    public function check(array $payload): array
    {
        $url = trim((string) apply_filters('license.check_url', (string) config('pos.license.check_url')));
        $apiKey = trim((string) config('pos.license.api_key', ''));
        $itemId = trim((string) config('pos.license.item_id', ''));

        if ($url === '') {
            return $this->failure('unreachable', 'no_server');
        }
        if ($apiKey === '') {
            return $this->failure('configuration', 'api_key_missing');
        }
        if (! ctype_digit($itemId) || (int) $itemId <= 0) {
            return $this->failure('configuration', 'item_not_configured');
        }

        $code = trim((string) ($payload['license_key'] ?? $payload['purchase_code'] ?? ''));

        try {
            $response = Http::timeout((int) config('pos.license.timeout', 15))
                ->acceptJson()
                ->asJson()
                ->withHeaders(['X-Api-Key' => $apiKey])
                ->withoutRedirecting()
                ->post($url, [
                    'purchase_code' => $code,
                    'domain_url' => $payload['install_url'] ?? $payload['domain_url'] ?? '',
                    'item_id' => (int) $itemId,
                ]);
        } catch (\Throwable) {
            // HTTP exceptions can include request credentials; never report the raw exception.
            return $this->failure('unreachable', 'unreachable');
        }

        $body = $response->json();

        $data = is_array($body) && is_array($body['data'] ?? null) ? $body['data'] : [];
        $reason = $data['reason'] ?? null;

        // Only documented business rejections invalidate a license. Authentication,
        // throttling, malformed requests and outages must preserve re-check status.
        $rejections = ['invalid', 'blacklisted', 'not_found', 'unknown_product', 'item_mismatch',
            'token_mismatch', 'limit_reached', 'rotation_throttled', 'domain_already_registered', 'already_registered'];
        if (is_array($body) && ($body['success'] ?? null) === false
            && in_array($response->status(), [200, 400, 403, 404, 409, 422], true)
            && in_array($reason, $rejections, true)) {
            return $this->rejected($reason, $response->status(), $body['message'] ?? null);
        }

        if (! $response->successful()) {
            $errorKey = match ($response->status()) {
                401 => 'api_unauthorized',
                429 => 'rate_limited',
                503 => 'unreachable',
                default => 'http',
            };

            return [
                'ok' => false, 'reason' => 'http_error', 'status' => $response->status(),
                'error' => __('installer.license.errors.'.$errorKey, ['status' => $response->status()]),
            ];
        }

        if (! is_array($body) || ($body['success'] ?? null) !== true
            || ! in_array($data['status'] ?? null, ['registered', 'already_registered'], true)
            || ! is_string($data['purchase_code'] ?? null) || ! hash_equals($code, $data['purchase_code'])
            || ! is_string($data['token'] ?? null) || trim($data['token']) === '') {
            return $this->failure('bad_response', 'bad_response');
        }

        $actualItemId = $data['item_id'] ?? null;
        if ((! is_int($actualItemId) && ! is_string($actualItemId))
            || ! hash_equals($itemId, (string) $actualItemId)) {
            return $this->rejected('item_mismatch', $response->status());
        }

        return ['ok' => true, 'status' => $response->status(), 'body' => array_merge($data, [
            'status' => 'valid',
            'registration_status' => $data['status'],
            'buyer_name' => $data['username'] ?? null,
            'support_until' => $data['supported_until'] ?? null,
            'max_installs' => $data['seats'] ?? null,
        ])];
    }

    private function failure(string $reason, string $error): array
    {
        return ['ok' => false, 'reason' => $reason, 'error' => __('installer.license.errors.'.$error)];
    }

    /** Read the current binding without creating or restoring a registration. */
    public function inspect(string $code, string $domain): array
    {
        $result = $this->operation('GET', 'codes/'.rawurlencode($code));
        if (! $result['ok'] || ($result['body']['status'] ?? null) === 'invalid') {
            return $result;
        }
        $data = $result['body'];
        if (($data['purchase_code'] ?? null) !== $code || ! is_array($data['domains'] ?? null)) {
            return $this->failure('bad_response', 'bad_response');
        }
        $expected = $this->normalizeDomain($domain);
        if ($expected === '') {
            return $this->failure('configuration', 'bad_response');
        }
        $matching = [];
        foreach ($data['domains'] as $binding) {
            if (! is_array($binding) || (! is_string($binding['domain_url'] ?? null) && ($binding['domain_url'] ?? null) !== null)
                || ! in_array($binding['status'] ?? null, ['active', 'revoked', 'expired'], true)) {
                return $this->failure('bad_response', 'bad_response');
            }
            if (($binding['domain_url'] ?? null) === null) {
                continue;
            }
            if ($this->normalizeDomain($binding['domain_url']) === $expected) {
                $matching[] = $binding['status'];
            }
        }
        if (in_array('active', $matching, true)) {
            return ['ok' => true, 'body' => ['status' => 'valid']];
        }

        return $this->rejected('domain_revoked', 200);
    }

    public function deregister(string $code, string $domain, string $token): array
    {
        if ($token === '') {
            return $this->failure('configuration', 'registration_token_missing');
        }
        $result = $this->operation('POST', 'deregister', [
            'purchase_code' => $code, 'domain_url' => $domain, 'token' => $token,
        ]);
        if (! $result['ok'] || ($result['body']['status'] ?? null) === 'invalid') {
            return $result;
        }
        if (($result['body']['purchase_code'] ?? null) !== $code
            || ! is_string($result['body']['domain_url'] ?? null)
            || $this->normalizeDomain($result['body']['domain_url']) !== $this->normalizeDomain($domain)) {
            return $this->failure('bad_response', 'bad_response');
        }

        return ['ok' => true, 'body' => ['status' => 'deregistered']];
    }

    private function operation(string $method, string $path, array $payload = []): array
    {
        $key = trim((string) config('pos.license.api_key'));
        $checkUrl = trim((string) apply_filters('license.check_url', (string) config('pos.license.check_url')));
        if ($key === '') {
            return $this->failure('configuration', 'api_key_missing');
        }
        if (! str_ends_with($checkUrl, '/validate')) {
            return $this->failure('configuration', 'no_server');
        }
        $url = substr($checkUrl, 0, -strlen('validate')).$path;
        try {
            $http = Http::acceptJson()->asJson()->withHeaders(['X-Api-Key' => $key])
                ->withoutRedirecting()->timeout((int) config('pos.license.timeout', 15));
            $response = $method === 'GET' ? $http->get($url) : $http->post($url, $payload);
        } catch (\Throwable) {
            return $this->failure('unreachable', 'unreachable');
        }
        $body = $response->json();
        $reason = is_array($body) ? data_get($body, 'data.reason') : null;
        if (is_array($body) && ($body['success'] ?? null) === false
            && in_array($response->status(), [400, 403, 404, 409, 422], true)
            && in_array($reason, ['invalid', 'not_found', 'blacklisted', 'token_mismatch', 'item_mismatch'], true)) {
            return $this->rejected($reason, $response->status(), $body['message'] ?? null);
        }
        if (! $response->successful()) {
            return ['ok' => false, 'reason' => 'http_error', 'error' => __('installer.license.errors.http', ['status' => $response->status()])];
        }
        if (! is_array($body) || ($body['success'] ?? null) !== true || ! is_array($body['data'] ?? null)) {
            return $this->failure('bad_response', 'bad_response');
        }

        return ['ok' => true, 'body' => $body['data']];
    }

    private function normalizeDomain(string $domain): string
    {
        $parts = parse_url(str_contains($domain, '://') ? $domain : 'https://'.$domain);
        if (! is_array($parts) || empty($parts['host'])) {
            return '';
        }

        return preg_replace('/^www\./', '', strtolower($parts['host']))
            .(isset($parts['port']) ? ':'.$parts['port'] : '')
            .rtrim($parts['path'] ?? '', '/');
    }

    public function matchesDomain(string $first, string $second): bool
    {
        $normalized = $this->normalizeDomain($first);

        return $normalized !== '' && $normalized === $this->normalizeDomain($second);
    }

    private function rejected(string $reason, int $status, mixed $message = null): array
    {
        return ['ok' => true, 'status' => $status, 'body' => [
            'status' => 'invalid',
            'reason' => $reason,
            'message' => is_string($message) && trim($message) !== ''
                ? $message
                : __('installer.license.errors.'.$reason),
        ]];
    }
}
