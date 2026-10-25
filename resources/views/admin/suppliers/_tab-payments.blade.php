{{-- Payments tab — every outflow to this supplier. A payment with no
     `purchase_id` is money on account (an advance, or the overpaid
     remainder of a split allocation). Voided rows stay visible and
     badged — the owner needs to see a reversal happened, not have the
     row vanish; the statement is what excludes them. --}}
<div class="card card-pad-0" x-data="dataTable({ rowsSelector: 'tbody > tr[data-dt-row]' })">
    <div class="dt-toolbar">
        <div>
            <div class="dt-toolbar-title">
                {{ __('suppliers.payments.title') }}
                (<span x-text="rowCount">{{ $payments->count() }}</span>)
            </div>
            <div class="card-title-sub">{{ __('suppliers.payments.sub') }}</div>
        </div>
        @if ($payments->isNotEmpty())
            <x-admin.dt-search :placeholder="__('suppliers.payments.search')" />
        @endif
        <div class="dt-toolbar-actions">
            <a href="{{ route('admin.supplier-payments.index', ['supplier_id' => $supplier->id]) }}"
               class="pos-btn pos-btn-xs pos-btn-ghost">
                {{ __('suppliers.payments.view_all') }}
            </a>
        </div>
    </div>

    @if ($payments->isEmpty())
        <div class="dt-empty">
            <span class="dt-empty-icon"><x-icon name="cash" class="w-5 h-5" /></span>
            <div class="dt-empty-title">{{ __('suppliers.payments.empty_title') }}</div>
            <div class="dt-empty-sub">{{ __('suppliers.payments.empty_sub') }}</div>
        </div>
    @else
        <div class="dt-scroll">
            <table class="dt-table">
                <thead>
                    <tr>
                        <th>{{ __('suppliers.payments.date') }}</th>
                        <th>{{ __('suppliers.payments.reference') }}</th>
                        <th>{{ __('suppliers.payments.method') }}</th>
                        <th>{{ __('suppliers.payments.note') }}</th>
                        <th class="num">{{ __('suppliers.payments.amount') }}</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($payments as $payment)
                        <tr class="prod-row" data-dt-row
                            data-dt-name="{{ $payment->purchase?->number ?: __('suppliers.payments.unallocated') }}"
                            @if ($payment->purchase_id)
                                @click="window.location.href = {{ \Illuminate\Support\Js::from(route('admin.purchases.show', $payment->purchase_id)) }}"
                            @endif>
                            <td>{{ $payment->payment_date ? format_date($payment->payment_date) : '—' }}</td>
                            <td>
                                @if ($payment->purchase?->number)
                                    <span class="mono">{{ $payment->purchase->number }}</span>
                                @else
                                    <span class="prod-badge prod-badge-info">{{ __('suppliers.payments.unallocated') }}</span>
                                @endif
                                @if ($payment->isVoided())
                                    <span class="prod-badge prod-badge-danger ms-1">{{ __('suppliers.payments.voided') }}</span>
                                @endif
                            </td>
                            <td>{{ $payment->isCreditApplication() ? __('supplier_payments.credit_application.method') : ($payment->paymentMethod?->name ?: '—') }}</td>
                            <td class="fg-secondary">{{ $payment->reference ?: ($payment->notes ?: '—') }}</td>
                            {{-- A voided payment moved no money; striking it keeps the
                                 row auditable without letting the eye add it up. --}}
                            <td class="num tnum font-semibold {{ $payment->isVoided() ? 'line-through fg-tertiary' : '' }}">
                                {{ format_money($payment->amount, null, $payment->currency_code) }}
                            </td>
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
            <div class="cust-tab-note">{{ __('suppliers.payments.capped', ['count' => number_format($rowLimit)]) }}</div>
        @endif
    @endif
</div>
