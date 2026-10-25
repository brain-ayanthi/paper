<x-admin-layout
    active="channel-orders"
    :title="__('commerce.orders.page_title')"
    :crumbs="[
        ['label' => __('admin.shell.brand_sub')],
        ['label' => __('commerce.orders.page_title')],
    ]">

    <div class="page-wide">
        <div class="page-header mb-6">
            <div>
                <h1 class="page-title">{{ __('commerce.orders.page_title') }}</h1>
                <p class="page-sub">{{ __('commerce.orders.page_sub') }}</p>
            </div>
            <a href="{{ route('admin.commerce.connections.index') }}" class="pos-btn pos-btn-sm pos-btn-ghost">
                <x-icon name="settings" class="w-4 h-4" />
                {{ __('commerce.actions.manage_channels') }}
            </a>
        </div>

        <div class="card card-pad-0">
            <div class="dt-toolbar">
                <div class="dt-toolbar-title">
                    {{ __('commerce.orders.page_title') }} ({{ number_format($orders->total()) }})
                </div>
                <div class="dt-toolbar-actions">
                    <button type="submit"
                            form="channel-order-filters"
                            class="pos-btn pos-btn-xs pos-btn-ghost"
                            title="{{ __('table.refresh') }}">
                        <x-icon name="refresh" class="w-3.5 h-3.5" />
                        {{ __('table.refresh') }}
                    </button>
                </div>
            </div>

            <form id="channel-order-filters" method="GET" action="{{ route('admin.commerce.orders.index') }}" class="inv-filter">
                <label class="field inv-filter-select">
                    <span class="field-label">{{ __('commerce.orders.filters.channel') }}</span>
                    <select name="connection" class="pos-input" x-data="enhancedSelect()">
                        <option value="">{{ __('commerce.orders.filters.all_channels') }}</option>
                        @foreach ($connections as $connection)
                            <option value="{{ $connection->getKey() }}" @selected($filters['connectionId'] === $connection->getKey())>
                                {{ $connection->name }} · {{ $connection->providerLabel() }}
                            </option>
                        @endforeach
                    </select>
                </label>
                <label class="field inv-filter-select">
                    <span class="field-label">{{ __('commerce.orders.filters.status') }}</span>
                    <select name="status" class="pos-input" x-data="enhancedSelect()">
                        <option value="">{{ __('commerce.orders.filters.all_statuses') }}</option>
                        @foreach ($statuses as $status)
                            <option value="{{ $status }}" @selected($filters['status'] === $status)>{{ __('commerce.status.'.$status) }}</option>
                        @endforeach
                    </select>
                </label>
                <label class="field inv-filter-search">
                    <span class="field-label">{{ __('commerce.orders.filters.search') }}</span>
                    <span class="inv-search">
                        <span class="inv-search-icon"><x-icon name="search" class="w-4 h-4" /></span>
                        <input type="search" name="q" value="{{ $filters['query'] }}" class="pos-input"
                               placeholder="{{ __('commerce.orders.filters.search_placeholder') }}">
                    </span>
                </label>
                <button type="submit" class="pos-btn pos-btn-primary">
                    <x-icon name="filter" class="w-4 h-4" />
                    {{ __('commerce.orders.filters.apply') }}
                </button>
                <x-admin.filter-reset route="admin.commerce.orders.index" />
            </form>

            @if ($orders->isEmpty())
                <div class="dt-empty">
                    <span class="dt-empty-icon"><x-icon name="receipt" class="w-5 h-5" /></span>
                    <div class="dt-empty-title">{{ __('commerce.orders.empty_title') }}</div>
                    <div class="dt-empty-sub">{{ __('commerce.orders.empty_sub') }}</div>
                </div>
            @else
                <div class="dt-scroll">
                    <table class="dt-table">
                        <thead>
                            <tr>
                                <th>{{ __('commerce.orders.columns.order') }}</th>
                                <th>{{ __('commerce.orders.columns.channel') }}</th>
                                <th>{{ __('commerce.orders.columns.customer') }}</th>
                                <th>{{ __('commerce.orders.columns.placed_at') }}</th>
                                <th>{{ __('commerce.orders.columns.allocations') }}</th>
                                <th>{{ __('commerce.orders.columns.status') }}</th>
                                <th class="num">{{ __('commerce.orders.columns.total') }}</th>
                                <th class="dt-actions-col"><span class="sr-only">{{ __('commerce.orders.columns.actions') }}</span></th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($orders as $order)
                                @php
                                    $statusClass = match ($order->status) {
                                        'completed' => 'badge-positive',
                                        'failed', 'cancelled' => 'badge-danger',
                                        'processing', 'allocated' => 'badge-warning',
                                        default => '',
                                    };
                                @endphp
                                <tr class="prod-row" data-dt-row
                                    @click="window.location.href = {{ \Illuminate\Support\Js::from(route('admin.commerce.orders.show', $order)) }}">
                                    <td>
                                        <a href="{{ route('admin.commerce.orders.show', $order) }}" class="font-medium" @click.stop>
                                            {{ $order->remote_order_number ?: '#'.$order->remote_order_id }}
                                        </a>
                                        <div class="text-xs fg-tertiary mt-1">{{ __('commerce.orders.remote_id') }}: {{ $order->remote_order_id }}</div>
                                    </td>
                                    <td>
                                        <div>{{ $order->connection->name }}</div>
                                        <div class="text-xs fg-tertiary mt-1">{{ $order->connection->providerLabel() }}</div>
                                    </td>
                                    <td>{{ $order->customer?->name ?: __('commerce.orders.online_customer') }}</td>
                                    <td>{{ $order->placed_at?->format('Y-m-d H:i') ?? '—' }}</td>
                                    <td>{{ $order->allocations_count }}</td>
                                    <td><span class="badge {{ $statusClass }}"><span class="badge-dot"></span>{{ __('commerce.status.'.$order->status) }}</span></td>
                                    <td class="num tnum">{{ format_money($order->grand_total, null, $order->currency_code) }}</td>
                                    <td class="dt-actions-col">
                                        <x-admin.row-actions>
                                            <x-admin.row-action
                                                :href="route('admin.commerce.orders.show', $order)"
                                                icon="eye"
                                                :label="__('commerce.actions.view')" />
                                        </x-admin.row-actions>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
                <div class="p-4 border-t">{{ $orders->links() }}</div>
            @endif
        </div>
    </div>
</x-admin-layout>
