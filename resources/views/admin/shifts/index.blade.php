<x-admin-layout
    active="shifts"
    :title="__('shifts.title')"
    :crumbs="[
        ['label' => __('admin.shell.brand_sub')],
        ['label' => __('sales.title')],
        ['label' => __('shifts.title')],
    ]">

    {{-- Server-paginated. Only the first page renders inline; search, sort and
         paging fetch one page from `admin.shifts.rows`. --}}
    <div class="page-wide" x-data="shiftsIndexPage({{ \Illuminate\Support\Js::from([
        'endpoint'   => route('admin.shifts.rows'),
        'perPage'    => $perPage,
        'total'      => $total,
        'page'       => 1,
        'totalPages' => $totalPages,
    ]) }})">

        <div class="page-header mb-6">
            <div>
                <h1 class="page-title">{{ __('shifts.title') }}</h1>
                <p class="page-sub">{{ __('shifts.sub') }}</p>
            </div>
            <div class="flex items-center gap-2">
                @if ($activeShift)
                    <a href="{{ route('admin.shifts.show', $activeShift) }}" class="pos-btn pos-btn-sm pos-btn-primary">
                        <x-icon name="receipt" class="w-4 h-4" />
                        {{ __('shifts.actions.view_active') }}
                    </a>
                @else
                    @can('create', App\Models\Shift::class)
                        <a href="{{ route('admin.shifts.open.form') }}" class="pos-btn pos-btn-sm pos-btn-primary">
                            <x-icon name="plus" class="w-4 h-4" />
                            {{ __('shifts.actions.open') }}
                        </a>
                    @endcan
                @endif
            </div>
        </div>

        <x-admin.summary-cards :cards="$summaryCards" />

        {{-- "No shifts at all" — distinct from "no rows match the search", which
             the data-table's own `.dt-empty` handles. --}}
        @if ($total === 0)
            <div class="card card-pad-0">
                <div class="dt-empty">
                    <span class="dt-empty-icon"><x-icon name="receipt" class="w-5 h-5" /></span>
                    <div class="dt-empty-title">{{ __('shifts.empty.title') }}</div>
                    <div class="dt-empty-sub">{{ __('shifts.empty.sub') }}</div>
                </div>
            </div>
        @else
            <div class="card card-pad-0">
                <div class="dt-toolbar">
                    <div class="dt-toolbar-title">
                        {{ __('shifts.title') }} (<span x-text="matchedCount.toLocaleString()">{{ number_format($total) }}</span>)
                    </div>
                    {{-- Own search input (no `.debounce` — the server mixin
                         debounces the fetch itself, so the shared dt-search would
                         stack two delays). --}}
                    <div class="dt-search">
                        <span class="dt-search-icon"><x-icon name="search" class="w-4 h-4" /></span>
                        <input type="search"
                               x-model="search"
                               placeholder="{{ __('table.search_placeholder') }}"
                               aria-label="{{ __('table.search_placeholder') }}">
                        <button type="button" class="dt-search-clear" x-show="isFiltered" x-cloak
                                @click="clearSearch()" aria-label="{{ __('table.search_clear') }}">
                            <x-icon name="x" class="w-3.5 h-3.5" />
                        </button>
                    </div>
                    <x-admin.dt-toolbar-actions :show-sort="false" />
                </div>

                <div class="dt-scroll">
                <table class="dt-table">
                    <thead>
                        <tr>
                            <th>{{ __('shifts.columns.number') }}</th>
                            <th>{{ __('shifts.columns.cashier') }}</th>
                            <th>{{ __('shifts.columns.terminal') }}</th>
                            <th>{{ __('shifts.columns.opened') }}</th>
                            <th>{{ __('shifts.columns.closed') }}</th>
                            <th class="num">{{ __('shifts.columns.sales_total') }}</th>
                            <th class="num">{{ __('shifts.columns.variance') }}</th>
                            <th>{{ __('shifts.columns.status') }}</th>
                        </tr>
                    </thead>
                    <tbody data-dt-rows="table">
                        @include('admin.shifts._rows', ['shifts' => $shifts])
                    </tbody>
                </table>
                </div>

                {{-- No search results. --}}
                <div class="dt-empty" x-show="dtReady && isEmpty" x-cloak>
                    <span class="dt-empty-icon"><x-icon name="search" class="w-5 h-5" /></span>
                    <div class="dt-empty-title">{{ __('table.empty.no_results_title') }}</div>
                    <div class="dt-empty-sub">{{ __('table.empty.no_results_sub') }}</div>
                </div>

                <x-admin.dt-pager />
            </div>
        @endif
    </div>
</x-admin-layout>
