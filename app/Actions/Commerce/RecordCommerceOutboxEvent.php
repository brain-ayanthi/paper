<?php

namespace App\Actions\Commerce;

use App\Models\CommerceConnection;
use App\Models\CommerceOutboxEvent;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/** Writes lightweight work descriptors only; provider I/O happens later. */
final class RecordCommerceOutboxEvent
{
    /**
     * @param  array<string, mixed>  $payload
     * @return list<int>
     */
    public function execute(
        string $eventType,
        Model $aggregate,
        array $payload = [],
        ?string $requiredSetting = null,
        ?int $storeId = null,
        ?string $dedupeSource = null,
        ?int $connectionId = null,
        bool $coalescePending = false,
    ): array {
        if (! Schema::hasTable('commerce_connections') || ! Schema::hasTable('commerce_outbox_events')) {
            return [];
        }

        $connections = CommerceConnection::query()
            ->when($connectionId, fn ($query) => $query->withTrashed())
            // Targeted domain events (such as a channel fulfillment) must be
            // retained even while that channel is temporarily disabled. Other
            // broadcasts stay limited to connections that participate in sync.
            ->when(
                $connectionId,
                fn ($query) => $query->whereKey($connectionId),
                fn ($query) => $query->whereIn('status', [CommerceConnection::STATUS_CONNECTED, CommerceConnection::STATUS_ERROR]),
            )
            ->when($storeId, fn ($query) => $query->whereHas('storeMappings', fn ($mapping) => $mapping
                ->where('store_id', $storeId)
                ->where('is_active', true)
                ->where('sync_inventory', true)))
            ->get();

        $eventIds = [];

        foreach ($connections as $connection) {
            if ($requiredSetting && ! (bool) data_get($connection->settings, $requiredSetting, false)) {
                continue;
            }

            $source = $dedupeSource ?: (string) Str::uuid();
            $event = CommerceOutboxEvent::query()->firstOrCreate(
                [
                    'commerce_connection_id' => $connection->getKey(),
                    'dedupe_key' => mb_substr("{$eventType}:{$aggregate->getKey()}:{$source}", 0, 191),
                ],
                [
                    'event_type' => $eventType,
                    'aggregate_type' => $aggregate::class,
                    'aggregate_id' => $aggregate->getKey(),
                    'payload' => $payload,
                    'status' => CommerceOutboxEvent::STATUS_PENDING,
                    'available_at' => now('UTC'),
                ],
            );
            $eventIds[] = (int) $event->getKey();

            if ($coalescePending && $event->status === CommerceOutboxEvent::STATUS_PENDING) {
                CommerceOutboxEvent::query()
                    ->where('commerce_connection_id', $connection->getKey())
                    ->where('event_type', $eventType)
                    ->where('aggregate_type', $aggregate::class)
                    ->where('aggregate_id', $aggregate->getKey())
                    ->where('status', CommerceOutboxEvent::STATUS_PENDING)
                    ->where('id', '!=', $event->getKey())
                    ->update([
                        'status' => CommerceOutboxEvent::STATUS_SUPERSEDED,
                        'processed_at' => now('UTC'),
                        'last_error' => __('commerce.sync.skipped.newer_state_available'),
                    ]);
            }
        }

        if (in_array($eventType, CommerceOutboxEvent::REALTIME_TYPES, true)) {
            app(ScheduleImmediateCommerceProcessing::class)->outbox($eventIds);
        }

        return $eventIds;
    }
}
