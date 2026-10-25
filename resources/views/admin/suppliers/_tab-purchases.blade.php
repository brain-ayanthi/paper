{{-- Purchases tab — what we ordered from this supplier. Goods sent back
     live on their own Returns tab. Paginates client-side; the row cap is
     enforced by SupplierController::TAB_ROW_LIMIT and surfaced below the
     table when it bites, with a link out to the full-history screen. --}}
<div>
    <div class="card card-pad-0" x-data="dataTable({ rowsSelector: 'tbody > tr[data-dt-row]' })">
        <div class="dt-toolbar">
            <div class="dt-toolbar-title">
                {{ __('suppliers.purchases.list_title') }}
                (<span x-text="rowCount">{{ $purchases->count() }}</span>)
            </div>
            @if ($purchases->isNotEmpty())
                <x-admin.dt-search :placeholder="__('suppliers.purchases.search')" />
            @endif
            <div class="dt-toolbar-actions">
                <a href="{{ route('admin.purchases.index', ['supplier_id' => $supplier->id]) }}" class="pos-btn pos-btn-xs pos-btn-ghost">
                    {{ __('suppliers.purchases.view_all') }}
                </a>
            </div>
        </div>

        @if ($purchases->isEmpty())
            <div class="dt-empty">
                <span class="dt-empty-icon"><x-icon name="truck" class="w-5 h-5" /></span>
                <div class="dt-empty-title">{{ __('suppliers.purchases.empty_title') }}</div>
                <div class="dt-empty-sub">{{ __('suppliers.purchases.empty_sub') }}</div>
            </div>
        @else
            <div class="dt-scroll">
                <table class="dt-table">
                    <thead>
                        <tr>
                            <th>{{ __('suppliers.purchases.number') }}</th>
                            <th>{{ __('suppliers.purchases.invoice') }}</th>
                            <th>{{ __('suppliers.purchases.date') }}</th>
                            <th>{{ __('suppliers.purchases.store') }}</th>
                            <th class="num">{{ __('suppliers.purchases.total') }}</th>
                            <th class="num">{{ __('suppliers.purchases.paid') }}</th>
                            <th class="num">{{ __('suppliers.purchases.balance') }}</th>
                            <th>{{ __('suppliers.purchases.status') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($purchases as $purchase)
                            @php
                                $badge = match ($purchase->status) {
                                    'received', 'partially_paid', 'paid' => 'positive',
                                    default                              => 'muted',
                                };
                            @endphp
                            <tr class="prod-row" data-dt-row data-dt-name="{{ $purchase->number }} {{ $purchase->supplier_invoice_number }}"
                                @click="window.location.href = {{ \Illuminate\Support\Js::from(route('admin.purchases.show', $purchase)) }}">
                                <td class="mono">{{ $purchase->number }}</td>
                                <td class="mono fg-secondary">{{ $purchase->supplier_invoice_number ?: '—' }}</td>
                                <td>{{ format_date($purchase->purchase_date) }}</td>
                                <td>{{ $purchase->store?->name ?: '—' }}</td>
                                <td class="num tnum">{{ format_money($purchase->grand_total) }}</td>
                                <td class="num tnum">{{ format_money($purchase->paid_total) }}</td>
                                <td class="num tnum @if (bccomp((string) $purchase->balance_due, '0', 4) > 0) text-amber-600 dark:text-amber-400 font-semibold @endif">
                                    {{ format_money($purchase->balance_due) }}
                                </td>
                                <td><span class="prod-badge prod-badge-{{ $badge }}">{{ __('purchases.status.'.$purchase->status) }}</span></td>
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

            @if ($purchases->count() >= $rowLimit)
                <div class="cust-tab-note">{{ __('suppliers.purchases.capped', ['count' => number_format($rowLimit)]) }}</div>
            @endif
        @endif
    </div>

</div>
