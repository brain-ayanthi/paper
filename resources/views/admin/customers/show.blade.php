<x-admin-layout
    active="customers"
    :title="$customer->name"
    :crumbs="[
        ['label' => __('admin.shell.brand_sub')],
        ['label' => __('customers.crumb_parent')],
        ['label' => __('customers.title'), 'href' => route('admin.customers.index')],
        ['label' => $customer->name],
    ]">

    {{-- The unified customer view (docs/features/customers.md §7.2).
         One page, one fetch: every tab's rows ship with the response and
         the tab strip only reveals them, so switching tabs costs nothing
         on the shared hosting this product targets. --}}
    <div class="page-wide" x-data="customerShowPage({ initialTab: {{ \Illuminate\Support\Js::from(request()->query('tab', 'overview')) }} })">
        <div class="page-header mb-6">
            <div class="flex items-start gap-3">
                <a href="{{ route('admin.customers.index') }}" class="pos-btn pos-btn-sm pos-btn-ghost" aria-label="{{ __('customers.title') }}">
                    <x-icon name="back" class="w-4 h-4" />
                </a>
                <div>
                    <h1 class="page-title">
                        {{ $customer->name }}
                        @if ($customer->is_business)
                            <span class="prod-badge prod-badge-muted ms-2">{{ __('customers.badges.business') }}</span>
                        @endif
                        @if (! $customer->is_active)
                            <span class="prod-badge prod-badge-muted ms-2">{{ __('customers.badges.inactive') }}</span>
                        @endif
                    </h1>
                    <p class="page-sub">
                        <span class="mono">{{ $customer->code ?: '—' }}</span>
                        @if ($customer->phone) · <span class="mono">{{ \App\Support\PhoneFormatter::pretty($customer->phone) }}</span> @endif
                        @if ($customer->email) · {{ $customer->display_email }} @endif
                    </p>
                </div>
            </div>

            <div class="flex items-center gap-2">
                {{-- Statement is the customer-facing receivables receipt;
                     always available, so the owner can hand a fresh
                     copy on any visit, not just when something's owed. --}}
                <a href="{{ route('admin.customers.statement.show', $customer) }}" class="pos-btn pos-btn-sm pos-btn-ghost">
                    <x-icon name="receipt" class="w-4 h-4" />
                    {{ __('customers.actions.statement') }}
                </a>
                @if (bccomp((string) ($customer->outstanding_balance ?? '0'), '0', 4) > 0)
                    @can('create', App\Models\SalePayment::class)
                        <a href="{{ route('admin.customer-payments.create', ['customer_id' => $customer->id]) }}"
                           class="pos-btn pos-btn-sm pos-btn-primary">
                            <x-icon name="cash" class="w-4 h-4" />
                            {{ __('customers.actions.record_payment') }}
                        </a>
                    @endcan
                @endif
                @can('delete', $customer)
                    <button type="button" class="pos-btn pos-btn-sm pos-btn-ghost pos-btn-danger"
                            @click="$store.confirm.show({
                                title: {{ \Illuminate\Support\Js::from(__('customers.confirm_delete.title', ['name' => $customer->name])) }},
                                message: {{ \Illuminate\Support\Js::from(__('customers.confirm_delete.message')) }},
                                intent: 'danger',
                                confirmLabel: {{ \Illuminate\Support\Js::from(__('customers.confirm_delete.confirm')) }},
                                onConfirm: () => $deleteForm({{ \Illuminate\Support\Js::from(route('admin.customers.destroy', $customer)) }}),
                            })">
                        <x-icon name="trash" class="w-4 h-4" />
                        {{ __('customers.actions.delete') }}
                    </button>
                @endcan
                @can('update', $customer)
                    <a href="{{ route('admin.customers.edit', $customer) }}" class="pos-btn pos-btn-sm pos-btn-primary">
                        <x-icon name="edit" class="w-4 h-4" />
                        {{ __('customers.actions.edit') }}
                    </a>
                @endcan
            </div>
        </div>

        {{-- KPI strip — the five numbers the owner actually asks about.
             Lives above the tabs so it stays true regardless of which tab
             is open. --}}
        <div class="grid grid-cols-2 lg:grid-cols-5 gap-3 mb-5">
            {{-- Lifetime spend is the Purchases tab, totalled — same gate
                 (SEC #102). Outstanding / credit / loyalty are columns on the
                 customer record itself, so `customers.view` covers them. --}}
            @if ($canSeeSales)
            <div class="card">
                <div class="card-body">
                    <div class="cust-kpi-label">{{ __('customers.kpis.lifetime') }}</div>
                    <div class="cust-kpi-value num tnum">{{ format_money($lifetimeSpend) }}</div>
                    <div class="cust-kpi-sub">{{ __('customers.kpis.lifetime_sub', ['count' => number_format($saleCount)]) }}</div>
                </div>
            </div>
            @endif
            <div class="card">
                <div class="card-body">
                    <div class="cust-kpi-label">{{ __('customers.kpis.outstanding') }}</div>
                    <div class="cust-kpi-value num tnum @if (bccomp((string) ($customer->outstanding_balance ?? '0'), '0', 4) > 0) text-amber-600 dark:text-amber-400 @endif">
                        {{ format_money($customer->outstanding_balance) }}
                    </div>
                </div>
            </div>
            <div class="card">
                <div class="card-body">
                    <div class="cust-kpi-label">{{ __('customers.kpis.credit') }}</div>
                    <div class="cust-kpi-value num tnum">{{ format_money($customer->store_credit_balance) }}</div>
                </div>
            </div>
            <div class="card">
                <div class="card-body">
                    <div class="cust-kpi-label">{{ __('customers.kpis.loyalty') }}</div>
                    <div class="cust-kpi-value num tnum">{{ number_format($customer->loyalty_points) }}</div>
                </div>
            </div>
            <div class="card">
                <div class="card-body">
                    <div class="cust-kpi-label">{{ __('customers.kpis.last_visit') }}</div>
                    <div class="cust-kpi-value">{{ $canSeeSales && $lastSaleDate ? format_date($lastSaleDate) : __('customers.kpis.last_visit_none') }}</div>
                    <div class="cust-kpi-sub">{{ __('customers.kpis.since') }} {{ format_date($customer->created_at) }}</div>
                </div>
            </div>
        </div>

        {{-- Tab strip. Counts sit on the tabs that hold a list so the owner
             can see there's history without opening it. --}}
        <div class="tabs mb-5" role="tablist">
            <button type="button" class="tab" role="tab" :class="{ 'is-active': tab === 'overview' }"
                    :aria-selected="tab === 'overview'" @click="select('overview')">
                {{ __('customers.tabs.overview') }}
            </button>
            {{-- Purchases / Returns / Statement / Payments carry this
                 customer's financial history, so each one appears only for a
                 role that could open that data on its own screen (SEC #102).
                 The controller skips the matching query too — hiding the
                 button alone would still ship the rows in the page source. --}}
            @if ($canSeeSales)
                <button type="button" class="tab" role="tab" :class="{ 'is-active': tab === 'purchases' }"
                        :aria-selected="tab === 'purchases'" @click="select('purchases')">
                    {{ __('customers.tabs.purchases') }}
                    @if ($sales->isNotEmpty())
                        <span class="tab-count">{{ number_format($sales->count()) }}</span>
                    @endif
                </button>
                <button type="button" class="tab" role="tab" :class="{ 'is-active': tab === 'returns' }"
                        :aria-selected="tab === 'returns'" @click="select('returns')">
                    {{ __('customers.tabs.returns') }}
                    @if ($returns->isNotEmpty())
                        <span class="tab-count">{{ number_format($returns->count()) }}</span>
                    @endif
                </button>
            @endif
            @if ($canSeeQuotations)
                <button type="button" class="tab" role="tab" :class="{ 'is-active': tab === 'quotations' }"
                        :aria-selected="tab === 'quotations'" @click="select('quotations')">
                    {{ __('customers.tabs.quotations') }}
                    @if ($quotations->isNotEmpty())<span class="tab-count">{{ number_format($quotations->count()) }}</span>@endif
                </button>
            @endif
            @if ($canSeeStatement)
                <button type="button" class="tab" role="tab" :class="{ 'is-active': tab === 'statement' }"
                        :aria-selected="tab === 'statement'" @click="select('statement')">
                    {{ __('customers.tabs.statement') }}
                </button>
            @endif
            @if ($canSeePayments)
                <button type="button" class="tab" role="tab" :class="{ 'is-active': tab === 'payments' }"
                        :aria-selected="tab === 'payments'" @click="select('payments')">
                    {{ __('customers.tabs.payments') }}
                    @if ($payments->isNotEmpty())
                        <span class="tab-count">{{ number_format($payments->count()) }}</span>
                    @endif
                </button>
            @endif
            {{-- Loyalty appears when the programme is on, or when this
                 customer has history — switching loyalty off must never
                 hide points somebody already holds. --}}
            @if ($loyaltyOn || $loyaltyRows->isNotEmpty())
                <button type="button" class="tab" role="tab" :class="{ 'is-active': tab === 'loyalty' }"
                        :aria-selected="tab === 'loyalty'" @click="select('loyalty')">
                    {{ __('customers.tabs.loyalty') }}
                    @if ($loyaltyRows->isNotEmpty())
                        <span class="tab-count">{{ number_format($loyaltyRows->count()) }}</span>
                    @endif
                </button>
            @endif
            <button type="button" class="tab" role="tab" :class="{ 'is-active': tab === 'addresses' }"
                    :aria-selected="tab === 'addresses'" @click="select('addresses')">
                {{ __('customers.tabs.addresses') }}
                @if ($customer->addresses->isNotEmpty())
                    <span class="tab-count">{{ number_format($customer->addresses->count()) }}</span>
                @endif
            </button>
        </div>

        <div x-show="tab === 'overview'" x-cloak>@include('admin.customers._tab-overview')</div>
        @if ($canSeeSales)
            <div x-show="tab === 'purchases'" x-cloak>@include('admin.customers._tab-purchases')</div>
            <div x-show="tab === 'returns'" x-cloak>@include('admin.customers._tab-returns')</div>
        @endif
        @if ($canSeeQuotations)
            <div x-show="tab === 'quotations'" x-cloak>@include('admin.customers._tab-quotations')</div>
        @endif
        @if ($canSeeStatement)
            <div x-show="tab === 'statement'" x-cloak>@include('admin.customers._tab-statement')</div>
        @endif
        @if ($canSeePayments)
            <div x-show="tab === 'payments'" x-cloak>@include('admin.customers._tab-payments')</div>
        @endif
        @if ($loyaltyOn || $loyaltyRows->isNotEmpty())
            <div x-show="tab === 'loyalty'" x-cloak>@include('admin.customers._tab-loyalty')</div>
        @endif
        <div x-show="tab === 'addresses'" x-cloak>@include('admin.customers._tab-addresses')</div>
    </div>
</x-admin-layout>
