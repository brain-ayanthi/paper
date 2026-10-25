<x-admin-layout
    active="sales-channels"
    :title="__('commerce.title')"
    :crumbs="[
        ['label' => __('admin.shell.brand_sub')],
        ['label' => __('commerce.title')],
    ]">

    <div class="page-wide">
        <x-admin.demo-lock-banner>{{ __('settings.demo.locked_banner') }}</x-admin.demo-lock-banner>

        <div class="page-header mb-6">
            <div>
                <h1 class="page-title">{{ __('commerce.title') }}</h1>
                <p class="page-sub">{{ __('commerce.sub') }}</p>
            </div>
            <div class="flex items-center gap-2 flex-wrap">
                <a href="{{ route('admin.commerce.orders.index') }}" class="pos-btn pos-btn-sm pos-btn-ghost">
                    <x-icon name="receipt" class="w-4 h-4" />
                    {{ __('commerce.actions.view_orders') }}
                </a>
                @can('commerce.manage')
                    @unless (pos_is_demo())
                        <a href="{{ route('admin.commerce.connections.create') }}" class="pos-btn pos-btn-sm pos-btn-primary">
                            <x-icon name="plus" class="w-4 h-4" />
                            {{ $connections->isEmpty() ? __('commerce.actions.add') : __('commerce.actions.add_another') }}
                        </a>
                    @endunless
                @endcan
            </div>
        </div>

        @if ($connections->isEmpty())
            <div class="card card-pad-0">
                <div class="dt-empty">
                    <span class="dt-empty-icon"><x-icon name="external" class="w-5 h-5" /></span>
                    <div class="dt-empty-title">{{ __('commerce.index.empty_title') }}</div>
                    <div class="dt-empty-sub">{{ __('commerce.index.empty_sub') }}</div>
                    @can('commerce.manage')
                        @unless (pos_is_demo())
                            <a href="{{ route('admin.commerce.connections.create') }}" class="pos-btn pos-btn-sm pos-btn-primary mt-4">{{ __('commerce.actions.add_first') }}</a>
                        @endunless
                    @endcan
                </div>
            </div>
        @else
            <div class="card card-pad-0">
                <div class="dt-scroll">
                    <table class="dt-table">
                        <thead>
                            <tr>
                                <th>{{ __('commerce.index.columns.channel') }}</th>
                                <th>{{ __('commerce.index.columns.provider') }}</th>
                                <th>{{ __('commerce.index.columns.status') }}</th>
                                <th>{{ __('commerce.index.columns.store_mappings') }}</th>
                                <th>{{ __('commerce.index.columns.imported_orders') }}</th>
                                <th>{{ __('commerce.index.columns.sync_queue') }}</th>
                                <th>{{ __('commerce.index.columns.last_tested') }}</th>
                                <th class="dt-actions-col"><span class="sr-only">{{ __('commerce.index.columns.actions') }}</span></th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($connections as $connection)
                                @php
                                    $statusClass = match ($connection->status) {
                                        'connected' => 'badge-positive',
                                        'error' => 'badge-danger',
                                        'disabled' => 'badge-warning',
                                        default => '',
                                    };
                                @endphp
                                <tr class="prod-row"
                                    @click="window.location.href = {{ \Illuminate\Support\Js::from(route('admin.commerce.connections.edit', $connection)) }}">
                                    <td>
                                        <div class="font-medium">{{ $connection->name }}</div>
                                        <div class="text-xs fg-tertiary mt-1">{{ $connection->shop_url }}</div>
                                    </td>
                                    <td>
                                        <div class="flex items-center gap-2">
                                            <img
                                                src="{{ asset('assets/providers/'.$connection->provider.'.png') }}"
                                                alt="{{ $connection->providerLabel() }}"
                                                class="w-8 h-8 object-cover shrink-0"
                                                width="32"
                                                height="32"
                                                loading="lazy">
                                            <span>{{ $connection->providerLabel() }}</span>
                                        </div>
                                    </td>
                                    <td><span class="badge {{ $statusClass }}"><span class="badge-dot"></span>{{ __('commerce.status.'.$connection->status) }}</span></td>
                                    <td>{{ $connection->store_mappings_count }}</td>
                                    <td>
                                        <a href="{{ route('admin.commerce.orders.index', ['connection' => $connection->getKey()]) }}"
                                           class="font-medium"
                                           @click.stop>{{ $connection->channel_orders_count }}</a>
                                        @if ($connection->failed_channel_orders_count)
                                            <span class="badge badge-danger ml-2">{{ __('commerce.index.orders_failed', ['count' => $connection->failed_channel_orders_count]) }}</span>
                                        @endif
                                    </td>
                                    <td>
                                        @php
                                            $pendingSync = $connection->pending_outbox_count + $connection->pending_webhook_count;
                                            $failedSync = $connection->failed_outbox_count + $connection->failed_webhook_count;
                                        @endphp
                                        <span class="badge {{ $failedSync ? 'badge-danger' : ($pendingSync ? 'badge-warning' : 'badge-positive') }}">
                                            {{ $failedSync ? __('commerce.index.queue_failed', ['count' => $failedSync]) : __('commerce.index.queue_pending', ['count' => $pendingSync]) }}
                                        </span>
                                    </td>
                                    <td>{{ $connection->last_tested_at?->diffForHumans() ?? __('commerce.status.never') }}</td>
                                    <td class="dt-actions-col">
                                        <x-admin.row-actions>
                                            <x-admin.row-action
                                                :href="route('admin.commerce.connections.edit', $connection)"
                                                icon="settings"
                                                :label="__('commerce.actions.configure')" />
                                        </x-admin.row-actions>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </div>
        @endif
    </div>
</x-admin-layout>
