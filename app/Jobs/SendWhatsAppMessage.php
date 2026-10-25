<?php

namespace App\Jobs;

use App\Models\WhatsAppConnection;
use App\Models\WhatsAppLog;
use App\Services\WhatsApp\Data\OutboundMessage;
use App\Services\WhatsApp\WhatsAppProviderRegistry;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use RuntimeException;
use Throwable;

class SendWhatsAppMessage implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 4;

    public array $backoff = [30, 300, 1800];

    public function __construct(public int $logId) {}

    public function handle(WhatsAppProviderRegistry $providers): void
    {
        $log = WhatsAppLog::query()->with('connection')->find($this->logId);
        if (! $log || ! $log->connection || in_array($log->status, [WhatsAppLog::STATUS_SENT, WhatsAppLog::STATUS_DELIVERED, WhatsAppLog::STATUS_READ], true)) {
            return;
        }
        if ($log->connection->status !== WhatsAppConnection::STATUS_CONNECTED) {
            $log->forceFill([
                'status' => WhatsAppLog::STATUS_FAILED,
                'error_message' => __('whatsapp.errors.connection_not_active'),
                'next_retry_at' => null,
            ])->save();

            return;
        }

        $payload = $log->payload ?? [];
        do_action('whatsapp.before_send', $log, $log->connection);
        $result = $providers->get($log->connection->provider)->send(
            $log->connection,
            new OutboundMessage(
                $log->recipient_phone,
                (string) ($payload['body'] ?? ''),
                $payload['template_name'] ?? null,
                (string) ($payload['locale'] ?? 'en'),
                array_values((array) ($payload['parameters'] ?? [])),
            ),
        );

        if ($result->successful) {
            $log->forceFill([
                'status' => WhatsAppLog::STATUS_SENT,
                'meta_message_id' => $result->messageId,
                'sent_at' => now(),
                'error_message' => null,
                'next_retry_at' => null,
            ])->save();
            do_action('whatsapp.after_send', $log->fresh(), $result);

            return;
        }

        $log->forceFill([
            'retry_count' => $log->retry_count + 1,
            'error_message' => $result->error,
            'next_retry_at' => $result->retryable ? now()->addSeconds($this->retryDelay()) : null,
            'status' => $result->retryable && $this->attempts() < $this->tries
                ? WhatsAppLog::STATUS_QUEUED
                : WhatsAppLog::STATUS_FAILED,
        ])->save();
        do_action('whatsapp.send_failed', $log->fresh(), $result);

        if ($result->retryable && $this->attempts() < $this->tries) {
            throw new RuntimeException($result->error ?: 'WhatsApp provider request failed.');
        }
    }

    public function failed(Throwable $exception): void
    {
        WhatsAppLog::query()->whereKey($this->logId)->update([
            'status' => WhatsAppLog::STATUS_FAILED,
            'error_message' => mb_substr($exception->getMessage(), 0, 65535),
            'next_retry_at' => null,
        ]);
    }

    private function retryDelay(): int
    {
        return $this->backoff[max(0, min($this->attempts() - 1, count($this->backoff) - 1))];
    }
}
