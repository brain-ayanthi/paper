{{-- Purchases tab — what this customer bought. Goods that came back
     live on their own Returns tab. Paginates client-side; the row cap is
     enforced in CustomerController::TAB_ROW_LIMIT and surfaced honestly
     below the table when it bites, with a link out to Sales history. --}}
<div>
    <div class="card card-pad-0" x-data="dataTable({ rowsSelector: 'tbody > tr[data-dt-row]' })">
        <div class="dt-toolbar">
            <div class="dt-toolbar-title">
                {{ __('customers.purchases.sales_title') }}
                (<span x-text="rowCount">{{ $sales->count() }}</span>)
            </div>
            @if ($sales->isNotEmpty())
                <x-admin.dt-search :placeholder="__('customers.purchases.number')" />
            @endif
            <div class="dt-toolbar-actions">
                <a href="{{ route('admin.sales.index', ['customer_id' => $customer->id]) }}" class="pos-btn pos-btn-xs pos-btn-ghost">
                    {{ __('customers.purchases.view_all') }}
                </a>
            </div>
        </div>

        @if ($sales->isEmpty())
            <div class="dt-empty">
                <span class="dt-empty-icon"><x-icon name="receipt" class="w-5 h-5" /></span>
                <div class="dt-empty-title">{{ __('customers.purchases.empty_title') }}</div>
                <div class="dt-empty-sub">{{ __('customers.purchases.empty_sub') }}</div>
            </div>
        @else
            <div class="dt-scroll">
                <table class="dt-table">
                    <thead>
                        <tr>
                            <th>{{ __('customers.purchases.number') }}</th>
                            <th>{{ __('customers.purchases.date') }}</th>
                            <th>{{ __('customers.purchases.store') }}</th>
                            <th class="num">{{ __('customers.purchases.total') }}</th>
                            <th class="num">{{ __('customers.purchases.paid') }}</th>
                            <th class="num">{{ __('customers.purchases.balance') }}</th>
                            <th>{{ __('customers.purchases.status') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($sales as $sale)
                            @php
                                $badge = match ($sale->status) {
                                    'completed'           => 'positive',
                                    'held', 'placed'      => 'info',
                                    'voided'              => 'muted',
                                    'partially_refunded',
                                    'refunded'            => 'warning',
                                    default               => 'muted',
                                };
                            @endphp
                            <tr class="prod-row" data-dt-row data-dt-name="{{ $sale->number }}"
                                @click="window.location.href = {{ \Illuminate\Support\Js::from(route('admin.sales.show', $sale)) }}">
                                <td class="mono">{{ $sale->number }}</td>
                                <td>{{ format_date($sale->sale_date) }}</td>
                                <td>{{ $sale->store?->name ?: '—' }}</td>
                                <td class="num tnum">{{ format_money($sale->grand_total) }}</td>
                                <td class="num tnum">{{ format_money($sale->paid_total) }}</td>
                                <td class="num tnum @if (bccomp((string) $sale->balance_due, '0', 4) > 0) text-amber-600 dark:text-amber-400 font-semibold @endif">
                                    {{ format_money($sale->balance_due) }}
                                </td>
                                <td><span class="prod-badge prod-badge-{{ $badge }}">{{ __('sales.statuses.'.$sale->status) }}</span></td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

            <div class="dt-empty" x-show="dtReady && isEmpty" x-cloak>
                <span class="dt-empty-icon"><x-icon name="search" class="w-5 h-5" /></span>
                <div class="dt-empty-title">{{ __('table.empty.no_results_title') }}</div>
                <div class="dt-empty-sub">{{ __('table.empty.no_results_sub') }}</div>
            </div>

            <x-admin.dt-pager />

            @if ($sales->count() >= $rowLimit)
                <div class="cust-tab-note">{{ __('customers.purchases.capped', ['count' => number_format($rowLimit)]) }}</div>
            @endif
        @endif
    </div>
</div>
