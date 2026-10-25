<?php

namespace App\Actions\Commerce;

use App\Models\CommerceConnection;
use App\Models\CommerceOutboxEvent;
use App\Services\Commerce\Exceptions\CommerceSyncException;
use App\Services\Commerce\Exceptions\CommerceSyncSkipped;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Throwable;

final readonly class ProcessCommerceOutbox
{
    public function __construct(
        private SyncCommerceProduct $products,
        private SyncCommerceCustomer $customers,
        private SyncCommerceInventory $inventory,
        private SyncCommerceFulfillment $fulfillments,
    ) {}

    /**
     * @param  list<int>|null  $eventIds
     * @param  list<string>|null  $eventTypes
     * @return array{processed:int,retried:int,failed:int,skipped:int}
     */
    public function run(
        ?int $limit = null,
        ?int $connectionId = null,
        ?array $eventIds = null,
        ?array $eventTypes = null,
    ): array {
        $counters = ['processed' => 0, 'retried' => 0, 'failed' => 0, 'skipped' => 0];
        if (! Schema::hasTable('commerce_outbox_events') || $eventIds === []) {
            return $counters;
        }

        $this->recoverAbandonedClaims();
        $ids = CommerceOutboxEvent::query()->due()
            ->when($connectionId, fn ($query) => $query->where('commerce_connection_id', $connectionId))
            ->when($eventIds !== null, fn ($query) => $query->whereKey($eventIds))
            ->when($eventTypes !== null, fn ($query) => $query->whereIn('event_type', $eventTypes))
            ->inProcessingOrder()
            ->limit($limit ?? (int) config('commerce.processing.batch_size', 25))
            ->pluck('id');

        foreach ($ids as $id) {
            $event = $this->claim((int) $id);
            if (! $event) {
                continue;
            }

            try {
                $connection = $event->connection;
                if (! $connection || ! in_array($connection->status, [CommerceConnection::STATUS_CONNECTED, CommerceConnection::STATUS_ERROR], true)) {
                    if ($event->event_type === 'fulfillment.sync') {
                        throw new CommerceSyncException(__('commerce.sync.skipped.connection_unavailable'));
                    }
                    throw new CommerceSyncSkipped(__('commerce.sync.skipped.connection_unavailable'));
                }

                match ($event->event_type) {
                    CommerceOutboxEvent::TYPE_PRODUCT_SYNC => $this->products->execute(
                        $connection,
                        (int) $event->aggregate_id,
                        (bool) data_get($event->payload, 'force', false),
                    ),
                    CommerceOutboxEvent::TYPE_CUSTOMER_SYNC => $this->customers->execute($connection, (int) $event->aggregate_id),
                    CommerceOutboxEvent::TYPE_INVENTORY_SYNC => $this->inventory->execute($connection, $event->payload ?? []),
                    CommerceOutboxEvent::TYPE_FULFILLMENT_SYNC => $this->fulfillments->execute($connection, (int) $event->aggregate_id),
                    default => throw new CommerceSyncSkipped(__('commerce.sync.skipped.event_not_supported', ['event' => $event->event_type])),
                };

                $event->forceFill([
                    'status' => CommerceOutboxEvent::STATUS_PROCESSED,
                    'processed_at' => now('UTC'),
                    'last_error' => null,
                ])->save();
                $connection->forceFill(['last_reconciled_at' => now('UTC')])->saveQuietly();
                $counters['processed']++;
            } catch (CommerceSyncSkipped $e) {
                $event->forceFill([
                    'status' => CommerceOutboxEvent::STATUS_SUPERSEDED,
                    'processed_at' => now('UTC'),
                    'last_error' => $e->getMessage(),
                ])->save();
                $counters['skipped']++;
            } catch (CommerceSyncException $e) {
                $outcome = $this->recordFailure($event, $e, $e->retryable);
                $counters[$outcome]++;
            } catch (Throwable $e) {
                Log::error('Commerce outbox processing failed.', [
                    'event_id' => $event->getKey(),
                    'event_type' => $event->event_type,
                    'exception' => $e,
                ]);
                $outcome = $this->recordFailure($event, $e, true);
                $counters[$outcome]++;
            }
        }

        return $counters;
    }

    private function claim(int $id): ?CommerceOutboxEvent
    {
        return DB::transaction(function () use ($id): ?CommerceOutboxEvent {
            $event = CommerceOutboxEvent::query()->due()->lockForUpdate()->find($id);
            if (! $event) {
                return null;
            }
            $event->forceFill([
                'status' => CommerceOutboxEvent::STATUS_PROCESSING,
                'attempts' => $event->attempts + 1,
            ])->save();

            return $event->load('connection');
        });
    }

    private function recordFailure(CommerceOutboxEvent $event, Throwable $e, bool $retryable): string
    {
        $maxAttempts = (int) config('commerce.processing.max_attempts', 8);
        $retry = $retryable && $event->attempts < $maxAttempts;
        $event->forceFill([
            'status' => $retry ? CommerceOutboxEvent::STATUS_PENDING : CommerceOutboxEvent::STATUS_FAILED,
            'available_at' => $retry ? now('UTC')->addSeconds($this->backoff($event->attempts)) : null,
            'processed_at' => $retry ? null : now('UTC'),
            'last_error' => mb_substr($e->getMessage(), 0, 65535),
        ])->save();

        return $retry ? 'retried' : 'failed';
    }

    private function backoff(int $attempt): int
    {
        $base = (int) config('commerce.processing.retry_after_seconds', 30);
        $maximum = (int) config('commerce.processing.max_retry_after_seconds', 3600);

        return min($maximum, $base * (2 ** max(0, $attempt - 1)));
    }

    private function recoverAbandonedClaims(): void
    {
        CommerceOutboxEvent::query()
            ->where('status', CommerceOutboxEvent::STATUS_PROCESSING)
            ->where('updated_at', '<', now('UTC')->subMinutes((int) config('commerce.processing.stale_after_minutes', 10)))
            ->update([
                'status' => CommerceOutboxEvent::STATUS_PENDING,
                'available_at' => now('UTC'),
                'last_error' => __('commerce.sync.errors.abandoned_claim_recovered'),
            ]);
    }
}
