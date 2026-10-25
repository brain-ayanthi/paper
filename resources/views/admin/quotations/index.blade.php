<x-admin-layout
    active="quotations"
    :title="__('quotations.title')"
    :crumbs="[['label' => __('admin.shell.brand_sub')], ['label' => __('quotations.title')]]">
    <div class="page-wide" x-data="salesIndexPage({{ \Illuminate\Support\Js::from([
        'endpoint' => route('admin.quotations.rows'),
        'perPage' => $perPage,
        'total' => $total,
        'page' => 1,
        'totalPages' => $totalPages,
    ]) }})">
        <div class="page-header mb-6">
            <div>
                <h1 class="page-title">{{ __('quotations.title') }}</h1>
                <p class="page-sub">{{ __('quotations.sub') }}</p>
            </div>
            @can('create', App\Models\Quotation::class)
                <a href="{{ route('admin.quotations.create') }}" class="pos-btn pos-btn-sm pos-btn-primary">
                    <x-icon name="plus" class="w-4 h-4" />
                    {{ __('quotations.actions.create') }}
                </a>
            @endcan
        </div>

        <div class="card card-pad-0">
            <div class="dt-toolbar">
                <div class="dt-toolbar-title">{{ __('quotations.title') }} (<span x-text="matchedCount.toLocaleString()">{{ number_format($total) }}</span>)</div>
                <x-admin.dt-toolbar-actions :show-sort="false" />
            </div>
            <form method="GET" action="{{ route('admin.quotations.index') }}" class="inv-filter" x-ref="filterForm" @submit.prevent="applyFilters()">
                <label class="field inv-filter-select">
                    <span class="field-label">{{ __('quotations.columns.status') }}</span>
                    <select name="status" class="pos-input" x-data="enhancedSelect()" @change="applyFilters()">
                        <option value="all" @selected($filters['status'] === 'all')>{{ __('quotations.filters.all_statuses') }}</option>
                        @foreach (['draft', 'sent', 'accepted', 'declined', 'expired', 'converted', 'cancelled', 'superseded'] as $status)
                            <option value="{{ $status }}" @selected($filters['status'] === $status)>{{ __('quotations.statuses.'.$status) }}</option>
                        @endforeach
                    </select>
                </label>
                <label class="field inv-filter-date">
                    <span class="field-label">{{ __('quotations.filters.from') }}</span>
                    <input type="text" name="from" value="{{ $filters['from'] }}" class="pos-input js-datepicker" data-fp-submit-on-change="1">
                </label>
                <label class="field inv-filter-date">
                    <span class="field-label">{{ __('quotations.filters.to') }}</span>
                    <input type="text" name="to" value="{{ $filters['to'] }}" class="pos-input js-datepicker" data-fp-submit-on-change="1">
                </label>
                <label class="field inv-filter-search">
                    <span class="field-label">&nbsp;</span>
                    <span class="inv-search">
                        <span class="inv-search-icon"><x-icon name="search" class="w-4 h-4" /></span>
                        <input type="search" name="q" value="{{ $filters['q'] }}" class="pos-input" placeholder="{{ __('quotations.filters.search') }}" @input.debounce.400ms="applyFilters()">
                    </span>
                </label>
                <button type="button" class="inv-filter-reset" @click="resetFilters()">
                    <x-icon name="x" class="w-3.5 h-3.5" />
                    <span>{{ __('table.filter_reset') }}</span>
                </button>
            </form>

            <div class="dt-empty" x-show="dtReady && isEmpty" x-cloak>
                <span class="dt-empty-icon"><x-icon name="note" class="w-5 h-5" /></span>
                <div class="dt-empty-title">{{ __('quotations.empty.title') }}</div>
                <div class="dt-empty-sub">{{ __('quotations.empty.sub') }}</div>
            </div>
            <div class="dt-scroll" x-show="!isEmpty">
                <table class="dt-table">
                    <thead><tr>
                        <th>{{ __('quotations.columns.number') }}</th>
                        <th>{{ __('quotations.columns.issue_date') }}</th>
                        <th>{{ __('quotations.columns.valid_until') }}</th>
                        <th>{{ __('quotations.columns.store') }}</th>
                        <th>{{ __('quotations.columns.customer') }}</th>
                        <th>{{ __('quotations.columns.owner') }}</th>
                        <th>{{ __('quotations.columns.status') }}</th>
                        <th class="num">{{ __('quotations.columns.total') }}</th>
                        <th class="dt-actions-col"><span class="sr-only">{{ __('quotations.columns.actions') }}</span></th>
                    </tr></thead>
                    <tbody data-dt-rows="table">@include('admin.quotations._rows', ['quotations' => $quotations])</tbody>
                </table>
            </div>
            <x-admin.dt-pager />
        </div>
    </div>
</x-admin-layout>
