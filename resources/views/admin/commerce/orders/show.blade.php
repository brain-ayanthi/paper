<x-admin-layout
    active="channel-orders"
    :title="__('commerce.orders.detail_title', ['order' => $order->remote_order_number ?: $order->remote_order_id])"
    :crumbs="[
        ['label' => __('admin.shell.brand_sub')],
        ['label' => __('commerce.orders.page_title'), 'href' => route('admin.commerce.orders.index')],
        ['label' => $order->remote_order_number ?: $order->remote_order_id],
    ]">

    @php
        $orderLabel = (string) ($order->remote_order_number ?: $order->remote_order_id);
        $displayOrderLabel = str_starts_with($orderLabel, '#') ? $orderLabel : '#'.$orderLabel;
        $statusClass = match ($order->status) {
            'completed' => 'badge-positive',
            'failed', 'cancelled' => 'badge-danger',
            'processing', 'allocated' => 'badge-warning',
            default => '',
        };
        $shipping = (array) ($order->shipping_address ?? []);
        $fulfillmentLabel = match ($order->fulfillment_status) {
            'fulfilled' => __('commerce.orders.fulfillment_status.fulfilled'),
            'partial', 'partially_fulfilled' => __('commerce.orders.fulfillment_status.partial'),
            'unfulfilled' => __('commerce.orders.fulfillment_status.unfulfilled'),
            'on_hold' => __('commerce.orders.fulfillment_status.on_hold'),
            'scheduled' => __('commerce.orders.fulfillment_status.scheduled'),
            null, '' => '—',
            default => __('commerce.orders.fulfillment_status.unknown'),
        };
        $customerName = $order->customer?->name ?: __('commerce.orders.online_customer');
        $customerEmail = (string) ($order->customer?->email ?? '');
        $showCustomerEmail = $customerEmail !== '' && strcasecmp($customerEmail, (string) $customerName) !== 0;
        $formatQuantity = static function (mixed $quantity): string {
            $formatted = trim((string) $quantity);
            if (str_contains($formatted, '.')) {
                $formatted = rtrim(rtrim($formatted, '0'), '.');
            }

            return $formatted === '' ? '0' : $formatted;
        };
    @endphp

    <div id="commerce-order-shell" class="page-wide">
        <div class="page-header mb-6">
            <div class="flex items-start gap-3">
                <a href="{{ route('admin.commerce.orders.index') }}"
                   class="pos-btn pos-btn-sm pos-btn-ghost"
                   aria-label="{{ __('commerce.actions.back_to_orders') }}">
                    <x-icon name="back" class="w-4 h-4" />
                </a>
                <div>
                    <div class="flex items-center gap-3 flex-wrap">
                        <h1 class="page-title mono">{{ $displayOrderLabel }}</h1>
                        <span class="badge {{ $statusClass }}">
                            <span class="badge-dot"></span>
                            {{ __('commerce.status.'.$order->status) }}
                        </span>
                    </div>
                    <p class="page-sub">{{ __('commerce.orders.detail_sub', ['channel' => $order->connection->name]) }}</p>
                </div>
            </div>
        </div>

        @if ($order->last_error)
            <x-alert type="danger" class="mb-5">
                <div class="font-semibold">{{ __('commerce.orders.allocation_error') }}</div>
                <div>{{ $order->last_error }}</div>
            </x-alert>
        @endif

        <div class="grid grid-cols-1 lg:grid-cols-[minmax(0,1fr)_360px] gap-5 items-start">
            <div class="space-y-5 min-w-0">
                <section class="card card-pad-0">
                    <div class="card-header">
                        <div>
                            <div class="card-title">{{ __('commerce.orders.items.title') }}</div>
                            <div class="card-title-sub">
                                {{ trans_choice('commerce.orders.items.count', $order->items->count(), ['count' => $order->items->count()]) }}
                            </div>
                        </div>
                    </div>
                    <div class="dt-scroll">
                        <table class="dt-table commerce-order-table">
                            <thead>
                                <tr>
                                    <th>{{ __('commerce.orders.items.product') }}</th>
                                    <th>{{ __('commerce.orders.items.sku') }}</th>
                                    <th class="num">{{ __('commerce.orders.items.quantity') }}</th>
                                    <th class="num">{{ __('commerce.orders.items.fulfilled') }}</th>
                                    <th class="num">{{ __('commerce.orders.items.refunded') }}</th>
                                    <th class="num">{{ __('commerce.orders.items.unit_price') }}</th>
                                    <th class="num">{{ __('commerce.orders.items.total') }}</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($order->items as $item)
                                    <tr>
                                        <td>
                                            <div class="font-medium">{{ $item->product_name_snapshot }}</div>
                                            @unless ($item->product_id)
                                                <div class="text-xs fg-danger mt-1">{{ __('commerce.orders.items.not_mapped') }}</div>
                                            @endunless
                                        </td>
                                        <td class="mono">{{ $item->sku_snapshot ?: '—' }}</td>
                                        <td class="num tnum">{{ $formatQuantity($item->quantity) }}</td>
                                        <td class="num tnum">{{ $formatQuantity($item->quantity_fulfilled) }}</td>
                                        <td class="num tnum">{{ $formatQuantity($item->quantity_refunded) }}</td>
                                        <td class="num tnum">{{ format_money($item->unit_price, null, $order->currency_code) }}</td>
                                        <td class="num tnum font-medium">{{ format_money(bcadd((string) $item->line_total, (string) $item->tax_amount, 4), null, $order->currency_code) }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                </section>

                <section>
                    <div class="flex items-center justify-between gap-3 mb-3">
                        <div>
                            <h2 class="font-semibold">{{ __('commerce.orders.allocations.title') }}</h2>
                            <p class="text-sm fg-tertiary mt-1">{{ __('commerce.orders.allocations.sub') }}</p>
                        </div>
                        @if (in_array($order->status, ['failed', 'completed'], true) && $order->allocations->isEmpty())
                            @can('commerce.orders.manage')
                                <form method="POST" action="{{ route('admin.commerce.orders.retry-allocation', $order) }}"
                                      data-ajax-form data-ajax-refresh="#commerce-order-shell">
                                    @csrf
                                    <button type="submit" class="pos-btn pos-btn-sm pos-btn-primary">
                                        <x-icon name="refresh" class="w-4 h-4" />
                                        {{ __('commerce.actions.retry_allocation') }}
                                    </button>
                                </form>
                            @endcan
                        @endif
                    </div>

                    @if ($order->allocations->isEmpty())
                        <div class="card">
                            <div class="card-body text-sm fg-tertiary">{{ __('commerce.orders.allocations.empty') }}</div>
                        </div>
                    @else
                        <div class="space-y-4">
                            @foreach ($order->allocations as $allocation)
                                @php
                                    $syncEvent = $syncEvents->get($allocation->getKey());
                                    $allocationStatusClass = match ($allocation->status) {
                                        'fulfilled' => 'badge-positive',
                                        'failed', 'cancelled' => 'badge-danger',
                                        'reserved' => 'badge-warning',
                                        default => '',
                                    };
                                    $canFulfill = $allocation->status === 'reserved'
                                        && auth()->user()?->can('commerce.orders.manage');
                                    $canRetrySync = $syncEvent?->status === 'failed'
                                        && auth()->user()?->can('commerce.sync.retry');
                                @endphp
                                <article class="card">
                                    <div class="card-header">
                                        <div class="flex items-center gap-3 min-w-0">
                                            <span class="commerce-order-icon w-9 h-9 rounded-lg inline-flex items-center justify-center fg-secondary shrink-0">
                                                <x-icon name="store" class="w-4 h-4" />
                                            </span>
                                            <div class="min-w-0">
                                                <h3 class="card-title truncate">{{ $allocation->store->name }}</h3>
                                                <div class="card-title-sub mono">{{ $allocation->store->code }}</div>
                                            </div>
                                        </div>
                                        <span class="badge {{ $allocationStatusClass }} shrink-0">
                                            <span class="badge-dot"></span>
                                            {{ __('commerce.status.'.$allocation->status) }}
                                        </span>
                                    </div>

                                    <div class="card-body">
                                        <ul class="space-y-3 text-sm">
                                            @foreach ($allocation->items as $allocationItem)
                                                <li class="flex items-start justify-between gap-4">
                                                    <div class="min-w-0">
                                                        <div class="font-medium truncate">{{ $allocationItem->orderItem->product_name_snapshot }}</div>
                                                        <div class="text-xs fg-tertiary mono mt-1">{{ $allocationItem->orderItem->sku_snapshot ?: '—' }}</div>
                                                    </div>
                                                    <span class="tnum font-medium shrink-0">
                                                        {{ $formatQuantity($allocationItem->quantity) }} ×
                                                    </span>
                                                </li>
                                            @endforeach
                                        </ul>

                                        <div class="commerce-order-divider flex items-center justify-between gap-3 mt-4 pt-3 border-t text-sm">
                                            <span class="fg-tertiary">{{ __('commerce.orders.allocations.total') }}</span>
                                            <span class="font-semibold tnum">{{ format_money($allocation->grand_total, null, $order->currency_code) }}</span>
                                        </div>

                                        @if ($allocation->sale)
                                            <div class="flex items-center justify-between gap-3 mt-3 text-sm">
                                                <span class="fg-tertiary">{{ __('commerce.orders.allocations.pos_sale') }}</span>
                                                @can('view', $allocation->sale)
                                                    <a class="link font-medium mono" href="{{ route('admin.sales.show', $allocation->sale) }}">
                                                        {{ $allocation->sale->number }}
                                                    </a>
                                                @else
                                                    <span class="font-medium mono">{{ $allocation->sale->number }}</span>
                                                @endcan
                                            </div>
                                            @if ($allocation->sale->returns->isNotEmpty())
                                                <div class="flex items-center justify-between gap-3 mt-3 text-sm">
                                                    <span class="fg-tertiary">{{ __('commerce.orders.allocations.pos_refunds') }}</span>
                                                    <span class="font-medium tnum">
                                                        {{ format_money($allocation->sale->returns->where('status', 'completed')->sum('grand_total'), null, $order->currency_code) }}
                                                    </span>
                                                </div>
                                            @endif
                                        @endif

                                        @if ($syncEvent)
                                            <div class="flex items-start justify-between gap-3 mt-3 text-sm">
                                                <span class="fg-tertiary">{{ __('commerce.orders.allocations.remote_sync') }}</span>
                                                <div class="text-end">
                                                    <span class="font-medium">{{ __('commerce.sync_status.'.$syncEvent->status) }}</span>
                                                    @if ($syncEvent->last_error)
                                                        <div class="fg-danger text-xs mt-1 max-w-md">{{ $syncEvent->last_error }}</div>
                                                    @endif
                                                </div>
                                            </div>
                                        @endif
                                    </div>

                                    @if ($canFulfill || $canRetrySync)
                                        <div class="card-footer flex-wrap">
                                            @if ($canFulfill)
                                                <form method="POST" action="{{ route('admin.commerce.orders.allocations.fulfill', [$order, $allocation]) }}"
                                                      data-ajax-form data-ajax-refresh="#commerce-order-shell"
                                                      onsubmit="return confirm(@js(__('commerce.orders.allocations.fulfill_confirm', ['store' => $allocation->store->name])))">
                                                    @csrf
                                                    <button type="submit" class="pos-btn pos-btn-sm pos-btn-primary">
                                                        <x-icon name="check" class="w-4 h-4" />
                                                        {{ __('commerce.actions.mark_fulfilled') }}
                                                    </button>
                                                </form>
                                            @endif
                                            @if ($canRetrySync)
                                                <form method="POST" action="{{ route('admin.commerce.orders.allocations.retry-sync', [$order, $allocation]) }}"
                                                      data-ajax-form data-ajax-refresh="#commerce-order-shell">
                                                    @csrf
                                                    <button type="submit" class="pos-btn pos-btn-sm pos-btn-ghost">
                                                        <x-icon name="refresh" class="w-4 h-4" />
                                                        {{ __('commerce.actions.retry_provider_sync') }}
                                                    </button>
                                                </form>
                                            @endif
                                        </div>
                                    @endif
                                </article>
                            @endforeach
                        </div>
                    @endif
                </section>
            </div>

            <aside class="space-y-5 min-w-0">
                <section class="card">
                    <div class="card-header">
                        <div>
                            <div class="card-title">{{ __('commerce.orders.summary.title') }}</div>
                            <div class="card-title-sub mono">{{ __('commerce.orders.summary.remote_id') }}: {{ $order->remote_order_id }}</div>
                        </div>
                    </div>
                    <div class="card-body">
                        <dl class="space-y-4 text-sm">
                            <div>
                                <dt class="fg-tertiary">{{ __('commerce.orders.summary.channel') }}</dt>
                                <dd class="mt-1 font-medium">{{ $order->connection->name }}</dd>
                                <dd class="text-xs fg-tertiary mt-1">{{ $order->connection->providerLabel() }}</dd>
                            </div>
                            <div class="grid grid-cols-2 gap-4">
                                <div>
                                    <dt class="fg-tertiary">{{ __('commerce.orders.summary.placed_at') }}</dt>
                                    <dd class="mt-1 tnum">{{ $order->placed_at ? format_datetime($order->placed_at) : '—' }}</dd>
                                </div>
                                <div>
                                    <dt class="fg-tertiary">{{ __('commerce.orders.summary.payment') }}</dt>
                                    <dd class="mt-1">{{ $order->financial_status ? __('commerce.orders.financial_status.'.$order->financial_status) : '—' }}</dd>
                                </div>
                            </div>
                            <div class="grid grid-cols-2 gap-4">
                                <div>
                                    <dt class="fg-tertiary">{{ __('commerce.orders.summary.fulfillment') }}</dt>
                                    <dd class="mt-1">{{ $fulfillmentLabel }}</dd>
                                </div>
                                <div>
                                    <dt class="fg-tertiary">{{ __('commerce.orders.summary.refunded') }}</dt>
                                    <dd class="mt-1 tnum">{{ format_money($order->refunded_total, null, $order->currency_code) }}</dd>
                                </div>
                            </div>
                        </dl>
                    </div>
                    <div class="card-footer justify-between">
                        <span class="font-semibold">{{ __('commerce.orders.summary.total') }}</span>
                        <span class="text-lg font-semibold tnum">{{ format_money($order->grand_total, null, $order->currency_code) }}</span>
                    </div>
                </section>

                <section class="card">
                    <div class="card-header">
                        <div class="card-title">{{ __('commerce.orders.customer.title') }}</div>
                    </div>
                    <div class="card-body">
                        <div class="flex items-start gap-3">
                            <span class="commerce-order-icon w-9 h-9 rounded-full inline-flex items-center justify-center fg-secondary shrink-0">
                                <x-icon name="user" class="w-4 h-4" />
                            </span>
                            <div class="min-w-0">
                                <div class="font-medium break-words">{{ $customerName }}</div>
                                @if ($showCustomerEmail)
                                    <div class="text-sm fg-secondary mt-1 break-all">{{ $customerEmail }}</div>
                                @endif
                                @if ($order->customer?->phone)
                                    <div class="text-sm fg-secondary mt-1 tnum">{{ $order->customer->phone }}</div>
                                @endif
                            </div>
                        </div>

                        @if ($shipping !== [])
                            <div class="commerce-order-divider border-t mt-4 pt-4">
                                <div class="font-medium text-sm mb-2">{{ __('commerce.orders.customer.shipping_address') }}</div>
                                <address class="not-italic text-sm fg-secondary leading-relaxed">
                                    @foreach (array_filter([
                                        trim(($shipping['first_name'] ?? '').' '.($shipping['last_name'] ?? '')),
                                        $shipping['company'] ?? null,
                                        $shipping['address_1'] ?? $shipping['address1'] ?? null,
                                        $shipping['address_2'] ?? $shipping['address2'] ?? null,
                                        trim(($shipping['city'] ?? '').' '.($shipping['state'] ?? $shipping['province'] ?? '').' '.($shipping['postcode'] ?? $shipping['zip'] ?? '')),
                                        $shipping['country'] ?? $shipping['country_code'] ?? null,
                                    ]) as $line)
                                        <div>{{ $line }}</div>
                                    @endforeach
                                </address>
                            </div>
                        @endif
                    </div>
                </section>
            </aside>
        </div>
    </div>
</x-admin-layout>
