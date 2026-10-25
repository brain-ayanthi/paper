<?php

namespace App\Http\Controllers\Admin;

use App\Actions\Commerce\QueueCommerceReconciliation;
use App\Actions\Commerce\SaveCommerceConnection;
use App\Actions\Commerce\SaveCommerceStoreMappings;
use App\Actions\Commerce\ScheduleImmediateCommerceProcessing;
use App\Actions\Commerce\TestCommerceConnection;
use App\Http\Controllers\Concerns\RespondsJsonOrRedirect;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\Commerce\SaveCommerceConnectionRequest;
use App\Http\Requests\Admin\Commerce\SaveCommerceStoreMappingsRequest;
use App\Models\CommerceConnection;
use App\Models\CommerceOutboxEvent;
use App\Models\PaymentMethod;
use App\Models\Store;
use App\Services\Commerce\CommerceCredentialManager;
use App\Services\Commerce\CommerceProviderRegistry;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class CommerceConnectionController extends Controller
{
    use RespondsJsonOrRedirect;

    public function index(): View
    {
        $this->authorize('viewAny', CommerceConnection::class);

        return view('admin.commerce.index', [
            'connections' => CommerceConnection::query()
                ->withCount([
                    'storeMappings',
                    'channelOrders',
                    'channelOrders as failed_channel_orders_count' => fn ($query) => $query->where('status', 'failed'),
                    'outboxEvents as pending_outbox_count' => fn ($query) => $query->whereIn('status', ['pending', 'processing']),
                    'outboxEvents as realtime_outbox_count' => fn ($query) => $query
                        ->whereIn('status', ['pending', 'processing'])
                        ->whereIn('event_type', CommerceOutboxEvent::REALTIME_TYPES),
                    'outboxEvents as bulk_outbox_count' => fn ($query) => $query
                        ->whereIn('status', ['pending', 'processing'])
                        ->whereIn('event_type', CommerceOutboxEvent::BULK_TYPES),
                    'outboxEvents as failed_outbox_count' => fn ($query) => $query->where('status', 'failed'),
                    'webhookEvents as pending_webhook_count' => fn ($query) => $query->whereIn('status', ['pending', 'processing']),
                    'webhookEvents as failed_webhook_count' => fn ($query) => $query->where('status', 'failed'),
                ])
                ->ordered()
                ->get(),
        ]);
    }

    public function create(CommerceProviderRegistry $providers): View
    {
        $this->authorize('create', CommerceConnection::class);

        return view('admin.commerce.form', [
            'connection' => new CommerceConnection([
                'provider' => 'woocommerce',
                'api_version' => config('commerce.shopify.api_version'),
                'settings' => $this->defaultSettings(),
            ]),
            'providers' => $providers->all(),
            'displayCredentials' => [],
            'stores' => collect(),
            'mappings' => collect(),
            'remoteLocations' => [],
            'paymentMethods' => $this->paymentMethods(),
        ]);
    }

    public function store(SaveCommerceConnectionRequest $request, SaveCommerceConnection $save): JsonResponse|RedirectResponse
    {
        $this->guardDemo();
        $connection = $save->execute(null, $request->validated(), $request->user());

        return $this->jsonOrRedirect(
            $request,
            __('commerce.flash.created'),
            route('admin.commerce.connections.edit', $connection),
        );
    }

    public function edit(
        CommerceConnection $connection,
        CommerceProviderRegistry $providers,
        CommerceCredentialManager $credentialManager,
    ): View {
        $this->authorize('update', $connection);
        $provider = $providers->get($connection->provider);
        $connection->loadCount([
            'outboxEvents as pending_outbox_count' => fn ($query) => $query->whereIn('status', ['pending', 'processing']),
            'outboxEvents as realtime_outbox_count' => fn ($query) => $query
                ->whereIn('status', ['pending', 'processing'])
                ->whereIn('event_type', CommerceOutboxEvent::REALTIME_TYPES),
            'outboxEvents as bulk_outbox_count' => fn ($query) => $query
                ->whereIn('status', ['pending', 'processing'])
                ->whereIn('event_type', CommerceOutboxEvent::BULK_TYPES),
            'outboxEvents as failed_outbox_count' => fn ($query) => $query->where('status', 'failed'),
            'webhookEvents as pending_webhook_count' => fn ($query) => $query->whereIn('status', ['pending', 'processing']),
            'webhookEvents as failed_webhook_count' => fn ($query) => $query->where('status', 'failed'),
            'channelOrders as failed_channel_orders_count' => fn ($query) => $query->where('status', 'failed'),
        ]);

        return view('admin.commerce.form', [
            'connection' => $connection,
            'providers' => $providers->all(),
            'displayCredentials' => $credentialManager->forDisplay($provider, $connection->credentials ?? []),
            'stores' => Store::query()->active()->ordered()->get(),
            'mappings' => $connection->storeMappings()->get()->keyBy('store_id'),
            'remoteLocations' => (array) data_get($connection->settings, 'remote_locations', []),
            'paymentMethods' => $this->paymentMethods(),
        ]);
    }

    public function update(
        SaveCommerceConnectionRequest $request,
        CommerceConnection $connection,
        SaveCommerceConnection $save,
    ): JsonResponse|RedirectResponse {
        $this->guardDemo();
        $this->authorize('update', $connection);
        $save->execute($connection, $request->validated(), $request->user());

        return $this->jsonOrStay(
            $request,
            __('commerce.flash.updated'),
            route('admin.commerce.connections.edit', $connection),
            ['refresh' => '#commerce-connection-shell'],
        );
    }

    public function test(
        Request $request,
        CommerceConnection $connection,
        TestCommerceConnection $test,
        QueueCommerceReconciliation $reconcile,
    ): JsonResponse|RedirectResponse {
        $this->guardDemo();
        $this->authorize('update', $connection);
        $result = $test->execute($connection);
        if ($result->successful) {
            $reconcile->execute($connection->fresh());
        }

        if (! $result->successful) {
            return $this->jsonOrError(
                $request,
                $result->message,
                route('admin.commerce.connections.edit', $connection),
            );
        }

        return $this->jsonOrStay(
            $request,
            $result->message,
            route('admin.commerce.connections.edit', $connection),
            ['refresh' => '#commerce-connection-shell'],
        );
    }

    public function mappings(
        SaveCommerceStoreMappingsRequest $request,
        CommerceConnection $connection,
        SaveCommerceStoreMappings $save,
        QueueCommerceReconciliation $reconcile,
    ): JsonResponse|RedirectResponse {
        $this->guardDemo();
        $this->authorize('update', $connection);
        $save->execute($connection, $request->validated('mappings'));
        $reconcile->execute($connection->fresh());

        return $this->jsonOrStay(
            $request,
            __('commerce.flash.mappings_saved'),
            route('admin.commerce.connections.edit', $connection),
            ['refresh' => '#commerce-connection-shell'],
        );
    }

    public function sync(
        Request $request,
        CommerceConnection $connection,
        QueueCommerceReconciliation $reconcile,
        ScheduleImmediateCommerceProcessing $immediate,
    ): JsonResponse|RedirectResponse {
        $this->guardDemo();
        $this->authorize('update', $connection);

        $queued = $reconcile->execute($connection, forceProducts: true);
        $failedWebhookIds = $connection->webhookEvents()
            ->where('status', 'failed')
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->all();
        $requeuedWebhooks = $connection->webhookEvents()->whereKey($failedWebhookIds)->update([
            'status' => 'pending',
            'attempts' => 0,
            'available_at' => now('UTC'),
            'processed_at' => null,
            'last_error' => null,
        ]);
        $immediate->webhooks($failedWebhookIds);

        return $this->jsonOrStay($request, __('commerce.flash.sync_queued', [
            'queued' => array_sum($queued) + $requeuedWebhooks,
        ]), route('admin.commerce.connections.edit', $connection), [
            'refresh' => '#commerce-connection-shell',
        ]);
    }

    public function destroy(Request $request, CommerceConnection $connection): JsonResponse|RedirectResponse
    {
        $this->guardDemo();
        $this->authorize('delete', $connection);
        $connection->delete();

        return $this->jsonOrRedirect(
            $request,
            __('commerce.flash.disconnected'),
            route('admin.commerce.connections.index'),
        );
    }

    /** @return array<string, mixed> */
    private function defaultSettings(): array
    {
        return [
            'sync_products' => true,
            'publish_online_store' => true,
            'sync_customers' => true,
            'import_orders' => true,
            'payment_method_id' => PaymentMethod::query()
                ->active()
                ->where('code', 'card')
                ->value('id') ?: PaymentMethod::query()->active()->orderBy('sort_order')->orderBy('name')->value('id'),
            'inventory_master' => 'pos',
        ];
    }

    private function paymentMethods()
    {
        return PaymentMethod::query()
            ->active()
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get(['id', 'name', 'type']);
    }

    private function guardDemo(): void
    {
        abort_if(pos_is_demo(), 403, __('commerce.demo_locked'));
    }
}
