<?php

namespace App\Actions\Commerce;

use App\Models\CommerceConnection;
use App\Models\CommerceWebhookEvent;
use App\Services\Commerce\CommerceProviderRegistry;
use App\Services\Commerce\Exceptions\CommerceSyncException;
use App\Services\Commerce\Exceptions\CommerceSyncSkipped;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Throwable;

final readonly class ProcessCommerceWebhooks
{
    public function __construct(
        private CommerceProviderRegistry $providers,
        private ImportChannelOrder $orders,
        private ImportCommerceCustomer $customers,
    ) {}

    /**
     * @param  list<int>|null  $eventIds
     * @return array{processed:int,retried:int,failed:int,ignored:int}
     */
    public function run(?int $limit = null, ?int $connectionId = null, ?array $eventIds = null): array
    {
        $counters = ['processed' => 0, 'retried' => 0, 'failed' => 0, 'ignored' => 0];
        if (! Schema::hasTable('commerce_webhook_events') || $eventIds === []) {
            return $counters;
        }

        $this->recoverAbandonedClaims();
        $ids = CommerceWebhookEvent::query()->due()
            ->when($connectionId, fn ($query) => $query->where('commerce_connection_id', $connectionId))
            ->when($eventIds !== null, fn ($query) => $query->whereKey($eventIds))
            ->orderBy('id')
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
                    throw new CommerceSyncSkipped(__('commerce.sync.skipped.connection_unavailable'));
                }
                $provider = $this->providers->get($event->provider);
                $payload = $provider->prepareOrderWebhookPayload($connection, $event->topic, $event->payload ?? []);
                $order = $provider->normalizeOrderWebhook($event->topic, $payload);
                $customer = $provider->normalizeCustomerWebhook($event->topic, $event->payload ?? []);
                if ($order) {
                    if (! (bool) data_get($connection->settings, 'import_orders', false)) {
                        throw new CommerceSyncSkipped(__('commerce.sync.skipped.order_import_disabled'));
                    }
                    $this->orders->execute($connection, $order);
                } elseif ($customer) {
                    if (! (bool) data_get($connection->settings, 'sync_customers', false)) {
                        throw new CommerceSyncSkipped(__('commerce.sync.skipped.customer_sync_disabled'));
                    }
                    $this->customers->execute($connection, $customer);
                } else {
                    throw new CommerceSyncSkipped(__('commerce.sync.skipped.webhook_not_supported', ['topic' => $event->topic]));
                }

                $event->forceFill([
                    'status' => CommerceWebhookEvent::STATUS_PROCESSED,
                    'processed_at' => now('UTC'),
                    'last_error' => null,
                ])->save();
                $connection->forceFill(['last_reconciled_at' => now('UTC')])->saveQuietly();
                $counters['processed']++;
            } catch (CommerceSyncSkipped $e) {
                $event->forceFill([
                    'status' => CommerceWebhookEvent::STATUS_IGNORED,
                    'processed_at' => now('UTC'),
                    'last_error' => $e->getMessage(),
                ])->save();
                $counters['ignored']++;
            } catch (CommerceSyncException $e) {
                $outcome = $this->recordFailure($event, $e, $e->retryable);
                $counters[$outcome]++;
            } catch (Throwable $e) {
                Log::error('Commerce webhook processing failed.', [
                    'event_id' => $event->getKey(),
                    'topic' => $event->topic,
                    'exception' => $e,
                ]);
                $outcome = $this->recordFailure($event, $e, true);
                $counters[$outcome]++;
            }
        }

        return $counters;
    }

    private function claim(int $id): ?CommerceWebhookEvent
    {
        return DB::transaction(function () use ($id): ?CommerceWebhookEvent {
            $event = CommerceWebhookEvent::query()->due()->lockForUpdate()->find($id);
            if (! $event) {
                return null;
            }
            $event->forceFill([
                'status' => CommerceWebhookEvent::STATUS_PROCESSING,
                'attempts' => $event->attempts + 1,
            ])->save();

            return $event->load('connection');
        });
    }

    private function recordFailure(CommerceWebhookEvent $event, Throwable $e, bool $retryable): string
    {
        $maxAttempts = (int) config('commerce.processing.max_attempts', 8);
        $retry = $retryable && $event->attempts < $maxAttempts;
        $event->forceFill([
            'status' => $retry ? CommerceWebhookEvent::STATUS_PENDING : CommerceWebhookEvent::STATUS_FAILED,
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
        CommerceWebhookEvent::query()
            ->where('status', CommerceWebhookEvent::STATUS_PROCESSING)
            ->where('updated_at', '<', now('UTC')->subMinutes((int) config('commerce.processing.stale_after_minutes', 10)))
            ->update([
                'status' => CommerceWebhookEvent::STATUS_PENDING,
                'available_at' => now('UTC'),
                'last_error' => __('commerce.sync.errors.abandoned_claim_recovered'),
            ]);
    }
}
