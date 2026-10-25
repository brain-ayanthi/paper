<x-admin-layout
    active="suppliers"
    :title="$supplier->name"
    :crumbs="[
        ['label' => __('admin.shell.brand_sub')],
        ['label' => __('suppliers.crumb_parent')],
        ['label' => __('suppliers.title'), 'href' => route('admin.suppliers.index')],
        ['label' => $supplier->name],
    ]">

    {{-- The unified supplier view — payables mirror of the customer view.
         One page, one fetch: every tab's rows ship with the response and
         the tab strip only reveals them. --}}
    <div class="page-wide" x-data="supplierShowPage({ initialTab: {{ \Illuminate\Support\Js::from(request()->query('tab', 'overview')) }} })">
        <div class="page-header mb-6">
            <div class="flex items-start gap-3">
                <a href="{{ route('admin.suppliers.index') }}" class="pos-btn pos-btn-sm pos-btn-ghost" aria-label="{{ __('suppliers.title') }}">
                    <x-icon name="back" class="w-4 h-4" />
                </a>
                <div>
                    <h1 class="page-title">
                        {{ $supplier->name }}
                        @if (! $supplier->is_active)
                            <span class="prod-badge prod-badge-muted ms-2">{{ __('suppliers.badges.inactive') }}</span>
                        @endif
                    </h1>
                    <p class="page-sub">
                        <span class="mono">{{ $supplier->code ?: '—' }}</span>
                        @if ($supplier->phone) · <span class="mono">{{ \App\Support\PhoneFormatter::pretty($supplier->phone) }}</span> @endif
                        @if ($supplier->email) · {{ $supplier->display_email }} @endif
                    </p>
                </div>
            </div>

            <div class="flex items-center gap-2">
                @if (bccomp((string) ($supplier->outstanding_balance ?? '0'), '0', 4) > 0)
                    @can('create', App\Models\PurchasePayment::class)
                        <a href="{{ route('admin.supplier-payments.create', ['supplier_id' => $supplier->id]) }}"
                           class="pos-btn pos-btn-sm pos-btn-primary">
                            <x-icon name="cash" class="w-4 h-4" />
                            {{ __('suppliers.payments.record') }}
                        </a>
                    @endcan
                @endif
                @can('delete', $supplier)
                    <button type="button" class="pos-btn pos-btn-sm pos-btn-ghost pos-btn-danger"
                            @click="$store.confirm.show({
                                title: {{ \Illuminate\Support\Js::from(__('suppliers.confirm_delete.title', ['name' => $supplier->name])) }},
                                message: {{ \Illuminate\Support\Js::from(__('suppliers.confirm_delete.message')) }},
                                intent: 'danger',
                                confirmLabel: {{ \Illuminate\Support\Js::from(__('suppliers.confirm_delete.confirm')) }},
                                onConfirm: () => $deleteForm({{ \Illuminate\Support\Js::from(route('admin.suppliers.destroy', $supplier)) }}),
                            })">
                        <x-icon name="trash" class="w-4 h-4" />
                        {{ __('suppliers.actions.delete') }}
                    </button>
                @endcan
                @can('update', $supplier)
                    <a href="{{ route('admin.suppliers.edit', $supplier) }}" class="pos-btn pos-btn-sm pos-btn-primary">
                        <x-icon name="edit" class="w-4 h-4" />
                        {{ __('suppliers.actions.edit') }}
                    </a>
                @endcan
            </div>
        </div>

        {{-- KPI strip. A NEGATIVE outstanding means we've overpaid — that
             reads as credit sitting on account, so it's labelled rather
             than shown as a confusing minus figure. --}}
        @php $owes = bccomp((string) ($supplier->outstanding_balance ?? '0'), '0', 4); @endphp
        <div class="grid grid-cols-2 lg:grid-cols-4 gap-3 mb-5">
            {{-- Lifetime spend is the Purchases tab, totalled — same gate
                 (SEC #102). Outstanding is a column on the supplier row. --}}
            @if ($canSeePurchases)
            <div class="card">
                <div class="card-body">
                    <div class="cust-kpi-label">{{ __('suppliers.kpis.lifetime_spend') }}</div>
                    <div class="cust-kpi-value num tnum">{{ format_money($lifetimeSpend) }}</div>
                    <div class="cust-kpi-sub">{{ __('suppliers.kpis.lifetime_sub', ['count' => number_format($purchaseCount)]) }}</div>
                </div>
            </div>
            @endif
            <div class="card">
                <div class="card-body">
                    <div class="cust-kpi-label">{{ __('suppliers.kpis.outstanding') }}</div>
                    <div class="cust-kpi-value num tnum @if ($owes > 0) text-amber-600 dark:text-amber-400 @endif">
                        {{ format_money($owes < 0 ? bcmul((string) $supplier->outstanding_balance, '-1', 4) : $supplier->outstanding_balance) }}
                    </div>
                    @if ($owes < 0)
                        <div class="cust-kpi-sub">{{ __('suppliers.kpis.outstanding_sub') }}</div>
                    @endif
                </div>
            </div>
            <div class="card">
                <div class="card-body">
                    <div class="cust-kpi-label">{{ __('suppliers.kpis.last_order') }}</div>
                    <div class="cust-kpi-value">{{ $canSeePurchases && $lastPurchaseDate ? format_date($lastPurchaseDate) : __('suppliers.kpis.last_order_none') }}</div>
                    <div class="cust-kpi-sub">{{ __('suppliers.kpis.since') }} {{ format_date($supplier->created_at) }}</div>
                </div>
            </div>
            <div class="card">
                <div class="card-body">
                    <div class="cust-kpi-label">{{ __('suppliers.kpis.terms') }}</div>
                    <div class="cust-kpi-value">
                        {{ $supplier->payment_terms_days !== null
                            ? $supplier->payment_terms_days.' '.__('suppliers.fields.days')
                            : '—' }}
                    </div>
                </div>
            </div>
        </div>

        <div class="tabs mb-5" role="tablist">
            <button type="button" class="tab" role="tab" :class="{ 'is-active': tab === 'overview' }"
                    :aria-selected="tab === 'overview'" @click="select('overview')">
                {{ __('suppliers.tabs.overview') }}
            </button>
            {{-- Each financial tab appears only for a role that could open
                 that data on its own screen (SEC #102). `suppliers.view` is
                 not `purchases.view`; the controller skips the matching query
                 too, so a hidden tab ships no rows in the page source. --}}
            @if ($canSeePurchases)
                <button type="button" class="tab" role="tab" :class="{ 'is-active': tab === 'purchases' }"
                        :aria-selected="tab === 'purchases'" @click="select('purchases')">
                    {{ __('suppliers.tabs.purchases') }}
                    @if ($purchases->isNotEmpty())
                        <span class="tab-count">{{ number_format($purchases->count()) }}</span>
                    @endif
                </button>
                <button type="button" class="tab" role="tab" :class="{ 'is-active': tab === 'returns' }"
                        :aria-selected="tab === 'returns'" @click="select('returns')">
                    {{ __('suppliers.tabs.returns') }}
                    @if ($returns->isNotEmpty())
                        <span class="tab-count">{{ number_format($returns->count()) }}</span>
                    @endif
                </button>
            @endif
            @if ($canSeeStatement)
                <button type="button" class="tab" role="tab" :class="{ 'is-active': tab === 'statement' }"
                        :aria-selected="tab === 'statement'" @click="select('statement')">
                    {{ __('suppliers.tabs.statement') }}
                </button>
            @endif
            @if ($canSeePayments)
                <button type="button" class="tab" role="tab" :class="{ 'is-active': tab === 'payments' }"
                        :aria-selected="tab === 'payments'" @click="select('payments')">
                    {{ __('suppliers.tabs.payments') }}
                    @if ($payments->isNotEmpty())
                        <span class="tab-count">{{ number_format($payments->count()) }}</span>
                    @endif
                </button>
            @endif
        </div>

        <div x-show="tab === 'overview'" x-cloak>@include('admin.suppliers._tab-overview')</div>
        @if ($canSeePurchases)
            <div x-show="tab === 'purchases'" x-cloak>@include('admin.suppliers._tab-purchases')</div>
            <div x-show="tab === 'returns'" x-cloak>@include('admin.suppliers._tab-returns')</div>
        @endif
        @if ($canSeeStatement)
            <div x-show="tab === 'statement'" x-cloak>@include('admin.suppliers._tab-statement')</div>
        @endif
        @if ($canSeePayments)
            <div x-show="tab === 'payments'" x-cloak>@include('admin.suppliers._tab-payments')</div>
        @endif
    </div>
</x-admin-layout>
