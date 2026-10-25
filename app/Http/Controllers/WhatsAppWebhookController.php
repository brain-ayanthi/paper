<?php

namespace App\Http\Controllers;

use App\Models\WhatsAppConnection;
use App\Models\WhatsAppLog;
use App\Models\WhatsAppWebhookEvent;
use App\Services\WhatsApp\WhatsAppProviderRegistry;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class WhatsAppWebhookController extends Controller
{
    public function verify(Request $request, WhatsAppConnection $connection): Response
    {
        $expected = (string) data_get($connection->credentials ?? [], 'verify_token', '');
        $provided = (string) $request->query('hub_verify_token', '');
        abort_unless($request->query('hub_mode') === 'subscribe' && $expected !== '' && hash_equals($expected, $provided), 403);

        return response((string) $request->query('hub_challenge', ''), 200)->header('Content-Type', 'text/plain');
    }

    public function receive(Request $request, WhatsAppConnection $connection, WhatsAppProviderRegistry $providers): JsonResponse
    {
        $provider = $providers->get($connection->provider);
        abort_unless($provider->verifyWebhook($connection, $request), 403);
        $payload = $request->json()->all();

        foreach ($provider->parseWebhook($connection, $payload) as $event) {
            $stored = WhatsAppWebhookEvent::query()->firstOrCreate(
                ['whatsapp_connection_id' => $connection->id, 'event_id' => $event->eventId],
                ['event_type' => $event->status, 'payload' => $payload, 'received_at' => now()],
            );
            if (! $stored->wasRecentlyCreated) {
                continue;
            }

            $log = WhatsAppLog::query()
                ->where('whatsapp_connection_id', $connection->id)
                ->where('meta_message_id', $event->messageId)
                ->first();
            if ($log) {
                $this->applyStatus($log, $event->status, $event->error);
            }
            $stored->forceFill(['processed_at' => now()])->save();
        }

        $connection->forceFill(['last_webhook_at' => now()])->save();

        return response()->json(['received' => true]);
    }

    private function applyStatus(WhatsAppLog $log, string $status, ?string $error): void
    {
        $rank = ['prepared' => 0, 'queued' => 1, 'sent' => 2, 'delivered' => 3, 'read' => 4];
        if ($status === WhatsAppLog::STATUS_FAILED) {
            if (! in_array($log->status, [WhatsAppLog::STATUS_DELIVERED, WhatsAppLog::STATUS_READ], true)) {
                $log->forceFill(['status' => $status, 'error_message' => $error])->save();
                do_action('whatsapp.status_updated', $log->fresh(), $status);
            }

            return;
        }
        if ($log->status === WhatsAppLog::STATUS_FAILED) {
            return;
        }
        if (! isset($rank[$status]) || ($rank[$status] <= ($rank[$log->status] ?? -1))) {
            return;
        }

        $updates = ['status' => $status, 'error_message' => null];
        if ($status === WhatsAppLog::STATUS_SENT) {
            $updates['sent_at'] = $log->sent_at ?: now();
        }
        if ($status === WhatsAppLog::STATUS_DELIVERED) {
            $updates['delivered_at'] = now();
        }
        if ($status === WhatsAppLog::STATUS_READ) {
            $updates['delivered_at'] = $log->delivered_at ?: now();
            $updates['read_at'] = now();
        }
        $log->forceFill($updates)->save();
        do_action('whatsapp.status_updated', $log->fresh(), $status);
    }
}
