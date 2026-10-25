<?php

namespace App\Http\Controllers\Admin;

use App\Actions\Commerce\AllocateChannelOrder;
use App\Actions\Commerce\FulfillChannelOrderAllocation;
use App\Http\Controllers\Concerns\RespondsJsonOrRedirect;
use App\Http\Controllers\Controller;
use App\Models\ChannelOrder;
use App\Models\ChannelOrderAllocation;
use App\Models\CommerceConnection;
use App\Models\CommerceOutboxEvent;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Throwable;

class ChannelOrderController extends Controller
{
    use RespondsJsonOrRedirect;

    public function index(Request $request): View
    {
        $this->viewGuard($request);
        $status = (string) $request->query('status', '');
        $connectionId = (int) $request->query('connection', 0);
        $query = trim((string) $request->query('q', ''));

        $orders = ChannelOrder::query()
            ->with(['connection:id,provider,name', 'customer:id,name'])
            ->withCount('allocations')
            ->when(in_array($status, $this->statuses(), true), fn ($builder) => $builder->where('status', $status))
            ->when($connectionId > 0, fn ($builder) => $builder->where('commerce_connection_id', $connectionId))
            ->when($query !== '', fn ($builder) => $builder->where(function ($search) use ($query) {
                $search->where('remote_order_number', 'like', "%{$query}%")
                    ->orWhere('remote_order_id', 'like', "%{$query}%")
                    ->orWhereHas('customer', fn ($customer) => $customer->where('name', 'like', "%{$query}%"));
            }))
            ->orderByDesc('placed_at')
            ->orderByDesc('id')
            ->paginate(25)
            ->withQueryString();

        return view('admin.commerce.orders.index', [
            'orders' => $orders,
            'connections' => CommerceConnection::query()->ordered()->get(['id', 'provider', 'name']),
            'filters' => compact('status', 'connectionId', 'query'),
            'statuses' => $this->statuses(),
        ]);
    }

    public function show(Request $request, ChannelOrder $order): View
    {
        $this->viewGuard($request);
        $order->load([
            'connection',
            'customer:id,name,email,phone',
            'items.product:id,name,sku',
            'items.variant:id,sku,attributes',
            'allocations.store:id,name,code',
            'allocations.sale:id,store_id,cashier_id,number,status,grand_total',
            'allocations.sale.returns:id,sale_id,number,grand_total,status',
            'allocations.items.orderItem',
        ]);

        $allocationIds = $order->allocations->pluck('id');
        $syncEvents = CommerceOutboxEvent::query()
            ->where('event_type', 'fulfillment.sync')
            ->where('aggregate_type', ChannelOrderAllocation::class)
            ->whereIn('aggregate_id', $allocationIds)
            ->latest('id')
            ->get()
            ->unique('aggregate_id')
            ->keyBy('aggregate_id');

        return view('admin.commerce.orders.show', compact('order', 'syncEvents'));
    }

    public function retryAllocation(
        Request $request,
        ChannelOrder $order,
        AllocateChannelOrder $allocate,
    ): JsonResponse|RedirectResponse {
        $this->manageGuard($request);
        $this->guardDemo();
        if ($order->status === ChannelOrder::STATUS_CANCELLED) {
            return $this->jsonOrError($request, __('commerce.orders.errors.order_not_retryable'));
        }
        if ($order->allocations()->exists()) {
            return $this->jsonOrError($request, __('commerce.orders.errors.allocation_already_exists'));
        }

        try {
            $order->forceFill(['status' => ChannelOrder::STATUS_PENDING, 'last_error' => null])->save();
            $allocate->execute($order);
        } catch (Throwable $e) {
            $order->forceFill([
                'status' => ChannelOrder::STATUS_FAILED,
                'last_error' => mb_substr($e->getMessage(), 0, 65535),
            ])->save();

            return $this->jsonOrError($request, $e->getMessage());
        }

        return $this->jsonOrStay(
            $request,
            __('commerce.orders.flash.allocation_retried'),
            route('admin.commerce.orders.show', $order),
            ['refresh' => '#commerce-order-shell'],
        );
    }

    public function fulfill(
        Request $request,
        ChannelOrder $order,
        ChannelOrderAllocation $allocation,
        FulfillChannelOrderAllocation $fulfill,
    ): JsonResponse|RedirectResponse {
        $this->manageGuard($request);
        $this->guardDemo();
        abort_unless((int) $allocation->channel_order_id === (int) $order->getKey(), 404);

        try {
            $fulfill->execute($allocation, $request->user()?->getKey());
        } catch (Throwable $e) {
            return $this->jsonOrError($request, $e->getMessage());
        }

        return $this->jsonOrStay(
            $request,
            __('commerce.orders.flash.fulfilled', [
                'store' => $allocation->store?->name ?: __('commerce.orders.unknown_store'),
            ]),
            route('admin.commerce.orders.show', $order),
            ['refresh' => '#commerce-order-shell'],
        );
    }

    public function retryFulfillmentSync(
        Request $request,
        ChannelOrder $order,
        ChannelOrderAllocation $allocation,
    ): JsonResponse|RedirectResponse {
        $this->retryGuard($request);
        $this->guardDemo();
        abort_unless((int) $allocation->channel_order_id === (int) $order->getKey(), 404);

        $updated = CommerceOutboxEvent::query()
            ->where('commerce_connection_id', $order->commerce_connection_id)
            ->where('event_type', 'fulfillment.sync')
            ->where('aggregate_type', ChannelOrderAllocation::class)
            ->where('aggregate_id', $allocation->getKey())
            ->where('status', CommerceOutboxEvent::STATUS_FAILED)
            ->update([
                'status' => CommerceOutboxEvent::STATUS_PENDING,
                'attempts' => 0,
                'available_at' => now('UTC'),
                'processed_at' => null,
                'last_error' => null,
            ]);

        if (! $updated) {
            return $this->jsonOrError($request, __('commerce.orders.errors.no_failed_fulfillment_sync'));
        }

        return $this->jsonOrStay(
            $request,
            __('commerce.orders.flash.fulfillment_sync_queued'),
            route('admin.commerce.orders.show', $order),
            ['refresh' => '#commerce-order-shell'],
        );
    }

    /** @return list<string> */
    private function statuses(): array
    {
        return [
            ChannelOrder::STATUS_PENDING,
            ChannelOrder::STATUS_ALLOCATED,
            ChannelOrder::STATUS_PROCESSING,
            ChannelOrder::STATUS_COMPLETED,
            ChannelOrder::STATUS_CANCELLED,
            ChannelOrder::STATUS_FAILED,
        ];
    }

    private function viewGuard(Request $request): void
    {
        abort_unless($request->user()?->hasPermission('commerce.view'), 403);
    }

    private function manageGuard(Request $request): void
    {
        abort_unless($request->user()?->hasPermission('commerce.orders.manage'), 403);
    }

    private function retryGuard(Request $request): void
    {
        abort_unless($request->user()?->hasPermission('commerce.sync.retry'), 403);
    }

    private function guardDemo(): void
    {
        abort_if(pos_is_demo(), 403, __('commerce.demo_locked'));
    }
}
