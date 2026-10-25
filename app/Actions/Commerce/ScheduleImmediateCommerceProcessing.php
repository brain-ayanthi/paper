<?php

namespace App\Actions\Commerce;

use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Runs a bounded fast-path after the HTTP response. The database rows remain
 * the durable fallback when the request terminates early or a provider fails.
 */
final class ScheduleImmediateCommerceProcessing
{
    /** @var array<int, int> */
    private array $outboxIds = [];

    /** @var array<int, int> */
    private array $webhookIds = [];

    private bool $scheduled = false;

    /** @param list<int> $eventIds */
    public function outbox(array $eventIds): void
    {
        foreach ($eventIds as $eventId) {
            $this->outboxIds[$eventId] = $eventId;
        }

        $this->schedule();
    }

    /** @param list<int> $eventIds */
    public function webhooks(array $eventIds): void
    {
        foreach ($eventIds as $eventId) {
            $this->webhookIds[$eventId] = $eventId;
        }

        $this->schedule();
    }

    private function schedule(): void
    {
        if ($this->scheduled
            || app()->runningInConsole()
            || ! (bool) config('commerce.processing.immediate_after_response', true)) {
            return;
        }

        $this->scheduled = true;
        app()->terminating(function (): void {
            $limit = max(1, (int) config('commerce.processing.immediate_batch_size', 25));

            try {
                $webhookIds = array_slice(array_values($this->webhookIds), 0, $limit);
                if ($webhookIds !== []) {
                    app(ProcessCommerceWebhooks::class)->run(
                        limit: count($webhookIds),
                        eventIds: $webhookIds,
                    );
                }

                $outboxIds = array_slice(array_values($this->outboxIds), 0, $limit);
                if ($outboxIds !== []) {
                    app(ProcessCommerceOutbox::class)->run(
                        limit: count($outboxIds),
                        eventIds: $outboxIds,
                    );
                }
            } catch (Throwable $e) {
                Log::warning('Immediate commerce processing failed; scheduler fallback retained.', [
                    'exception' => $e,
                ]);
            }
        });
    }
}
