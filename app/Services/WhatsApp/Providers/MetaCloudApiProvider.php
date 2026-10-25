<?php

namespace App\Services\WhatsApp\Providers;

use App\Models\WhatsAppConnection;
use App\Services\WhatsApp\Contracts\WhatsAppProvider;
use App\Services\WhatsApp\Data\ConnectionTestResult;
use App\Services\WhatsApp\Data\OutboundMessage;
use App\Services\WhatsApp\Data\SendResult;
use App\Services\WhatsApp\Data\WebhookStatus;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;

final class MetaCloudApiProvider implements WhatsAppProvider
{
    public function code(): string
    {
        return 'meta';
    }

    public function label(): string
    {
        return __('whatsapp.providers.meta');
    }

    public function settingsSchema(): array
    {
        return [
            'phone_number_id' => ['type' => 'text', 'required' => true, 'secret' => false, 'label' => __('whatsapp.fields.phone_number_id')],
            'waba_id' => ['type' => 'text', 'required' => false, 'secret' => false, 'label' => __('whatsapp.fields.waba_id')],
            'access_token' => ['type' => 'password', 'required' => true, 'secret' => true, 'label' => __('whatsapp.fields.access_token')],
            'app_secret' => ['type' => 'password', 'required' => true, 'secret' => true, 'label' => __('whatsapp.fields.app_secret')],
            'verify_token' => ['type' => 'password', 'required' => true, 'secret' => true, 'label' => __('whatsapp.fields.verify_token')],
        ];
    }

    public function capabilities(): array
    {
        return [
            'template_messages' => true,
            'session_messages' => true,
            'media' => true,
            'delivery_status' => true,
            'read_status' => true,
            'incoming_messages' => false,
            'interactive_buttons' => false,
        ];
    }

    public function testConnection(WhatsAppConnection $connection): ConnectionTestResult
    {
        $credentials = $connection->credentials ?? [];
        if (empty($credentials['phone_number_id']) || empty($credentials['access_token'])) {
            return new ConnectionTestResult(false, __('whatsapp.errors.credentials_incomplete'));
        }

        try {
            $response = Http::withToken((string) $credentials['access_token'])
                ->acceptJson()->timeout(15)
                ->get($this->graphUrl($connection, (string) $credentials['phone_number_id']), [
                    'fields' => 'id,display_phone_number,verified_name,quality_rating',
                ]);
        } catch (ConnectionException $e) {
            return new ConnectionTestResult(false, __('whatsapp.errors.connection_failed'));
        }

        if (! $response->successful()) {
            return new ConnectionTestResult(false, $this->apiError($response->json()));
        }

        return new ConnectionTestResult(true, __('whatsapp.flash.connection_ok'), $response->json());
    }

    public function send(WhatsAppConnection $connection, OutboundMessage $message): SendResult
    {
        $credentials = $connection->credentials ?? [];
        $phoneNumberId = (string) ($credentials['phone_number_id'] ?? '');
        $token = (string) ($credentials['access_token'] ?? '');
        if ($phoneNumberId === '' || $token === '') {
            return new SendResult(false, error: __('whatsapp.errors.credentials_incomplete'));
        }

        $payload = [
            'messaging_product' => 'whatsapp',
            'recipient_type' => 'individual',
            'to' => ltrim($message->recipient, '+'),
        ];

        if ($message->templateName) {
            $payload['type'] = 'template';
            $payload['template'] = [
                'name' => $message->templateName,
                'language' => ['code' => $message->locale],
            ];

            if ($message->parameters !== []) {
                $payload['template']['components'] = [[
                    'type' => 'body',
                    'parameters' => array_map(
                        fn (string $value) => ['type' => 'text', 'text' => $value],
                        $message->parameters,
                    ),
                ]];
            }
        } else {
            $payload['type'] = 'text';
            $payload['text'] = ['preview_url' => true, 'body' => $message->body];
        }

        try {
            $response = Http::withToken($token)->acceptJson()->timeout(20)
                ->post($this->graphUrl($connection, $phoneNumberId).'/messages', $payload);
        } catch (ConnectionException $e) {
            return new SendResult(false, error: __('whatsapp.errors.connection_failed'), retryable: true);
        }

        if (! $response->successful()) {
            $status = $response->status();

            return new SendResult(
                false,
                error: $this->apiError($response->json()),
                retryable: $status === 429 || $status >= 500,
            );
        }

        return new SendResult(true, (string) data_get($response->json(), 'messages.0.id'));
    }

    public function verifyWebhook(WhatsAppConnection $connection, Request $request): bool
    {
        $secret = (string) data_get($connection->credentials ?? [], 'app_secret', '');
        $signature = (string) $request->header('X-Hub-Signature-256', '');
        if ($secret === '' || ! str_starts_with($signature, 'sha256=')) {
            return false;
        }

        return hash_equals('sha256='.hash_hmac('sha256', $request->getContent(), $secret), $signature);
    }

    public function parseWebhook(WhatsAppConnection $connection, array $payload): array
    {
        $out = [];
        foreach ((array) data_get($payload, 'entry', []) as $entry) {
            foreach ((array) data_get($entry, 'changes', []) as $change) {
                foreach ((array) data_get($change, 'value.statuses', []) as $status) {
                    $messageId = (string) ($status['id'] ?? '');
                    $state = (string) ($status['status'] ?? '');
                    if ($messageId === '' || ! in_array($state, ['sent', 'delivered', 'read', 'failed'], true)) {
                        continue;
                    }
                    $timestamp = (string) ($status['timestamp'] ?? '');
                    $eventId = hash('sha256', $messageId.'|'.$state.'|'.$timestamp);
                    $out[] = new WebhookStatus(
                        $eventId,
                        $messageId,
                        $state,
                        data_get($status, 'errors.0.title') ?: data_get($status, 'errors.0.message'),
                    );
                }
            }
        }

        return $out;
    }

    private function graphUrl(WhatsAppConnection $connection, string $path): string
    {
        $version = preg_replace('/[^v0-9.]/', '', (string) $connection->setting('graph_version', config('services.whatsapp.meta_graph_version', 'v23.0')));

        return 'https://graph.facebook.com/'.($version ?: 'v23.0').'/'.ltrim($path, '/');
    }

    private function apiError(array $payload): string
    {
        return (string) (data_get($payload, 'error.message') ?: __('whatsapp.errors.provider_failed'));
    }
}
