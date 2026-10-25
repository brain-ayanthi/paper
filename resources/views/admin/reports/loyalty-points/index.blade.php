<x-admin-layout
    active="loyalty-points"
    :title="__('reports.loyalty_points.title')"
    :crumbs="[
        ['label' => __('admin.shell.brand_sub')],
        ['label' => __('reports.nav.section'), 'href' => route('admin.reports.index')],
        ['label' => __('reports.loyalty_points.title')],
    ]">

    <div class="page-wide">
        <div class="page-header mb-6">
            <div>
                <h1 class="page-title">{{ __('reports.loyalty_points.title') }}</h1>
                <p class="page-sub">{{ __('reports.loyalty_points.sub') }}</p>
            </div>
            @if ($rows->isNotEmpty())
                <div class="dropdown" x-data="dropdown">
                    <button type="button" class="pos-btn pos-btn-sm pos-btn-ghost" @click="toggle()" :aria-expanded="open">
                        <x-icon name="download" class="w-4 h-4" />
                        {{ __('reports.actions.export') }}
                        <x-icon name="chevron" class="w-4 h-4" />
                    </button>
                    <div class="dropdown-panel" x-show="open" x-cloak @click.outside="close()" @keydown.escape.window="close()">
                        <a href="{{ route('admin.reports.loyalty-points.export', array_filter(['format' => 'csv', 'from' => $from->toDateString(), 'to' => $to->toDateString(), 'store_id' => $storeId])) }}" class="dropdown-item" @click="close()">
                            <span class="dropdown-item-label">{{ __('reports.actions.export_csv') }}</span>
                        </a>
                        <a href="{{ route('admin.reports.loyalty-points.export', array_filter(['format' => 'xlsx', 'from' => $from->toDateString(), 'to' => $to->toDateString(), 'store_id' => $storeId])) }}" class="dropdown-item" @click="close()">
                            <span class="dropdown-item-label">{{ __('reports.actions.export_xlsx') }}</span>
                        </a>
                        <a href="{{ route('admin.reports.loyalty-points.export', array_filter(['format' => 'pdf', 'from' => $from->toDateString(), 'to' => $to->toDateString(), 'store_id' => $storeId])) }}" class="dropdown-item" @click="close()">
                            <span class="dropdown-item-label">{{ __('reports.actions.export_pdf') }}</span>
                        </a>
                    </div>
                </div>
            @endif
        </div>

        {{-- Liability is the headline: what every unspent point in the
             system would cost if it were all redeemed tomorrow. --}}
        <div class="grid grid-cols-2 lg:grid-cols-4 gap-3 mb-5">
            <div class="card"><div class="card-body">
                <div class="cust-kpi-label">{{ __('reports.loyalty_points.kpis.earned') }}</div>
                <div class="cust-kpi-value num tnum">{{ number_format($summary['earned']) }}</div>
            </div></div>
            <div class="card"><div class="card-body">
                <div class="cust-kpi-label">{{ __('reports.loyalty_points.kpis.redeemed') }}</div>
                <div class="cust-kpi-value num tnum">{{ number_format($summary['redeemed']) }}</div>
            </div></div>
            <div class="card"><div class="card-body">
                <div class="cust-kpi-label">{{ __('reports.loyalty_points.kpis.expired') }}</div>
                <div class="cust-kpi-value num tnum">{{ number_format($summary['expired']) }}</div>
            </div></div>
            <div class="card"><div class="card-body">
                <div class="cust-kpi-label">{{ __('reports.loyalty_points.kpis.liability') }}</div>
                <div class="cust-kpi-value num tnum">{{ format_money($summary['liability']) }}</div>
            </div></div>
        </div>

        <div class="card card-pad-0" x-data="dataTable({ rowsSelector: 'tbody > tr[data-dt-row]' })">
            <div class="dt-toolbar">
                <div class="dt-toolbar-title">
                    {{ __('reports.loyalty_points.list_title') }} (<span x-text="rowCount">{{ $rows->count() }}</span>)
                </div>
                <x-admin.dt-toolbar-actions />
            </div>
            <x-admin.report-filter-bar
                route="admin.reports.loyalty-points.index"
                :search-placeholder="__('reports.loyalty_points.filter.search')"
                :period="$period"
                :from="$from"
                :to="$to"
                :store-id="$storeId"
                :stores="$stores" />

            @if ($rows->isEmpty())
                <div class="dt-empty">
                    <span class="dt-empty-icon"><x-icon name="star" class="w-5 h-5" /></span>
                    <div class="dt-empty-title">{{ __('reports.loyalty_points.empty.title') }}</div>
                    <div class="dt-empty-sub">{{ __('reports.loyalty_points.empty.sub') }}</div>
                </div>
            @else
                <div class="dt-scroll">
                <table class="dt-table">
                    <thead>
                        <tr>
                            <th>{{ __('reports.loyalty_points.columns.customer') }}</th>
                            <th class="num">{{ __('reports.loyalty_points.columns.earned') }}</th>
                            <th class="num">{{ __('reports.loyalty_points.columns.redeemed') }}</th>
                            <th class="num">{{ __('reports.loyalty_points.columns.expired') }}</th>
                            <th class="num">{{ __('reports.loyalty_points.columns.net') }}</th>
                            <th class="num">{{ __('reports.loyalty_points.columns.balance') }}</th>
                            <th class="num">{{ __('reports.loyalty_points.columns.balance_value') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($rows as $row)
                            <tr class="prod-row" data-dt-row @click="window.location.href = {{ \Illuminate\Support\Js::from(route('admin.customers.show', $row['customer_id']).'?tab=loyalty') }}">
                                <td class="font-medium">
                                    {{ $row['name'] }}
                                    @if ($row['group_name'])
                                        <span class="prod-badge prod-badge-muted">{{ $row['group_name'] }}</span>
                                    @endif
                                    @if ($row['code'])
                                        <span class="fg-tertiary text-xs mono">{{ $row['code'] }}</span>
                                    @endif
                                </td>
                                <td class="num tnum">{{ number_format($row['earned']) }}</td>
                                <td class="num tnum fg-tertiary">{{ number_format($row['redeemed']) }}</td>
                                <td class="num tnum fg-tertiary">{{ number_format($row['expired']) }}</td>
                                <td class="num tnum {{ $row['net'] < 0 ? 'fg-danger' : '' }}">
                                    {{ $row['net'] > 0 ? '+' : '' }}{{ number_format($row['net']) }}
                                </td>
                                <td class="num tnum font-semibold">{{ number_format($row['balance']) }}</td>
                                <td class="num tnum fg-tertiary">{{ format_money($row['balance_value']) }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
                </div>
                <x-admin.dt-pager />
            @endif
        </div>
    </div>
</x-admin-layout>
