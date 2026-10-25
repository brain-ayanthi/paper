{{-- Payments tab — every inflow from this customer. Two shapes share
     `sale_payments`: tenders taken at the till (sale_id set) and
     standalone settlements against the outstanding balance (sale_id
     NULL). The reference column names which one a row is. --}}
<div class="card card-pad-0" x-data="dataTable({ rowsSelector: 'tbody > tr[data-dt-row]' })">
    <div class="dt-toolbar">
        <div>
            <div class="dt-toolbar-title">
                {{ __('customers.payments.title') }}
                (<span x-text="rowCount">{{ $payments->count() }}</span>)
            </div>
            <div class="card-title-sub">{{ __('customers.payments.sub') }}</div>
        </div>
        @if ($payments->isNotEmpty())
            <x-admin.dt-search :placeholder="__('customers.payments.reference')" />
        @endif
        <div class="dt-toolbar-actions">
            <a href="{{ route('admin.customer-payments.index', ['customer_id' => $customer->id]) }}"
               class="pos-btn pos-btn-xs pos-btn-ghost">
                {{ __('customers.payments.view_all') }}
            </a>
        </div>
    </div>

    @if ($payments->isEmpty())
        <div class="dt-empty">
            <span class="dt-empty-icon"><x-icon name="cash" class="w-5 h-5" /></span>
            <div class="dt-empty-title">{{ __('customers.payments.empty_title') }}</div>
            <div class="dt-empty-sub">{{ __('customers.payments.empty_sub') }}</div>
        </div>
    @else
        <div class="dt-scroll">
            <table class="dt-table">
                <thead>
                    <tr>
                        <th>{{ __('customers.payments.date') }}</th>
                        <th>{{ __('customers.payments.reference') }}</th>
                        <th>{{ __('customers.payments.method') }}</th>
                        <th>{{ __('customers.payments.note') }}</th>
                        <th class="num">{{ __('customers.payments.amount') }}</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($payments as $payment)
                        <tr class="prod-row" data-dt-row
                            data-dt-name="{{ $payment->sale?->number ?: __('customers.payments.settlement') }}"
                            @if ($payment->sale_id)
                                @click="window.location.href = {{ \Illuminate\Support\Js::from(route('admin.sales.show', $payment->sale_id)) }}"
                            @endif>
                            <td>{{ $payment->paid_at ? format_date($payment->paid_at) : '—' }}</td>
                            <td>
                                @if ($payment->sale?->number)
                                    <span class="mono">{{ $payment->sale->number }}</span>
                                @else
                                    <span class="prod-badge prod-badge-info">{{ __('customers.payments.settlement') }}</span>
                                @endif
                            </td>
                            <td>{{ $payment->paymentMethod?->name ?: '—' }}</td>
                            <td class="fg-secondary">{{ $payment->reference ?: ($payment->notes ?: '—') }}</td>
                            <td class="num tnum font-semibold">{{ format_money($payment->amount) }}</td>
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

        @if ($payments->count() >= $rowLimit)
            <div class="cust-tab-note">{{ __('customers.payments.capped', ['count' => number_format($rowLimit)]) }}</div>
        @endif
    @endif
</div>
