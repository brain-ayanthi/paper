{{-- Statement tab — the payables ledger for the default 3-month window.
     Signs are inverted vs the customer statement: a purchase CREDITS the
     supplier (we owe more), a payment or return DEBITS them. --}}
<div class="space-y-5">
    <div class="grid grid-cols-2 lg:grid-cols-4 gap-3">
        <div class="card">
            <div class="card-body">
                <div class="cust-kpi-label">{{ __('supplier_statement.kpis.opening') }}</div>
                <div class="cust-kpi-value num tnum">{{ format_money($ledger['opening_balance']) }}</div>
            </div>
        </div>
        <div class="card">
            <div class="card-body">
                <div class="cust-kpi-label">{{ __('supplier_statement.kpis.credits') }}</div>
                <div class="cust-kpi-value num tnum">{{ format_money($ledger['credits_total']) }}</div>
            </div>
        </div>
        <div class="card">
            <div class="card-body">
                <div class="cust-kpi-label">{{ __('supplier_statement.kpis.debits') }}</div>
                <div class="cust-kpi-value num tnum">{{ format_money($ledger['debits_total']) }}</div>
            </div>
        </div>
        <div class="card">
            <div class="card-body">
                <div class="cust-kpi-label">{{ __('supplier_statement.kpis.closing') }}</div>
                <div class="cust-kpi-value num tnum @if (bccomp($ledger['closing_balance'], '0', 4) > 0) text-amber-600 dark:text-amber-400 font-semibold @endif">
                    {{ format_money($ledger['closing_balance']) }}
                </div>
            </div>
        </div>
    </div>

    <div class="card card-pad-0" x-data="dataTable({ rowsSelector: 'tbody > tr[data-dt-row]' })">
        <div class="dt-toolbar">
            <div>
                <div class="dt-toolbar-title">{{ __('suppliers.statement_tab.title') }}</div>
                <div class="card-title-sub">
                    {{ __('suppliers.statement_tab.sub', [
                        'from' => $ledger['from']->format('Y-m-d'),
                        'to'   => $ledger['to']->format('Y-m-d'),
                    ]) }}
                </div>
            </div>
        </div>

        @if ($ledger['events']->isEmpty())
            <div class="dt-empty">
                <span class="dt-empty-icon"><x-icon name="check" class="w-5 h-5" /></span>
                <div class="dt-empty-title">{{ __('supplier_statement.empty.title') }}</div>
                <div class="dt-empty-sub">{{ __('supplier_statement.empty.sub') }}</div>
            </div>
        @else
            <div class="dt-scroll">
                <table class="dt-table">
                    <thead>
                        <tr>
                            <th>{{ __('supplier_statement.columns.date') }}</th>
                            <th>{{ __('supplier_statement.columns.type') }}</th>
                            <th>{{ __('supplier_statement.columns.reference') }}</th>
                            <th class="num">{{ __('supplier_statement.columns.debit') }}</th>
                            <th class="num">{{ __('supplier_statement.columns.credit') }}</th>
                            <th class="num">{{ __('supplier_statement.columns.running') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        {{-- Opening balance row — always first, even when zero. --}}
                        <tr class="prod-row" data-dt-row data-dt-name="opening">
                            <td>{{ $ledger['from']->format('Y-m-d') }}</td>
                            <td><span class="prod-badge prod-badge-muted">{{ __('supplier_statement.types.opening') }}</span></td>
                            <td class="fg-tertiary">—</td>
                            <td class="num tnum fg-tertiary">—</td>
                            <td class="num tnum fg-tertiary">—</td>
                            <td class="num tnum font-semibold">{{ format_money($ledger['opening_balance']) }}</td>
                        </tr>
                        @foreach ($ledger['events'] as $event)
                            @php
                                $badge = match ($event['type']) {
                                    'purchase' => 'muted',
                                    'payment'  => 'positive',
                                    'return'   => 'warning',
                                    default    => 'muted',
                                };
                            @endphp
                            <tr class="prod-row" data-dt-row data-dt-name="{{ $event['reference'] }}"
                                @if (! empty($event['purchase_id']))
                                    @click="window.location.href = {{ \Illuminate\Support\Js::from(route('admin.purchases.show', $event['purchase_id'])) }}"
                                @endif>
                                <td>{{ $event['date'] }}</td>
                                <td><span class="prod-badge prod-badge-{{ $badge }}">{{ __('supplier_statement.types.'.$event['type']) }}</span></td>
                                <td class="mono">{{ $event['reference'] }}</td>
                                <td class="num tnum">{{ bccomp($event['debit'], '0', 4) > 0 ? format_money($event['debit']) : '—' }}</td>
                                <td class="num tnum">{{ bccomp($event['credit'], '0', 4) > 0 ? format_money($event['credit']) : '—' }}</td>
                                <td class="num tnum font-semibold">{{ format_money($event['running_balance']) }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            <x-admin.dt-pager />
        @endif
    </div>
</div>
