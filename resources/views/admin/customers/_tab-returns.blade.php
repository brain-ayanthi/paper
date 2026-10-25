{{-- Returns tab — what this customer sent back, and what we refunded.
     Sale returns have no standalone index, so the full-history link opens
     this customer's filtered Sales history; each return row opens its sale. --}}
<div class="card card-pad-0" x-data="dataTable({ rowsSelector: 'tbody > tr[data-dt-row]' })">
    <div class="dt-toolbar">
        <div class="dt-toolbar-title">
            {{ __('customers.returns.title') }}
            (<span x-text="rowCount">{{ $returns->count() }}</span>)
        </div>
        @if ($returns->isNotEmpty())
            <x-admin.dt-search :placeholder="__('customers.returns.search')" />
        @endif
        <div class="dt-toolbar-actions">
            <a href="{{ route('admin.sales.index', ['customer_id' => $customer->id]) }}"
               class="pos-btn pos-btn-xs pos-btn-ghost">
                {{ __('customers.returns.view_all') }}
            </a>
        </div>
    </div>

    @if ($returns->isEmpty())
        <div class="dt-empty">
            <span class="dt-empty-icon"><x-icon name="refund" class="w-5 h-5" /></span>
            <div class="dt-empty-title">{{ __('customers.returns.empty_title') }}</div>
            <div class="dt-empty-sub">{{ __('customers.returns.empty_sub') }}</div>
        </div>
    @else
        <div class="dt-scroll">
            <table class="dt-table">
                <thead>
                    <tr>
                        <th>{{ __('customers.returns.number') }}</th>
                        <th>{{ __('customers.returns.date') }}</th>
                        <th>{{ __('customers.returns.against') }}</th>
                        <th class="num">{{ __('customers.returns.amount') }}</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($returns as $return)
                        <tr class="prod-row" data-dt-row data-dt-name="{{ $return->number }} {{ $return->sale?->number }}"
                            @if ($return->sale_id)
                                @click="window.location.href = {{ \Illuminate\Support\Js::from(route('admin.sales.show', $return->sale_id)) }}"
                            @endif>
                            <td class="mono">{{ $return->number }}</td>
                            <td>{{ format_date($return->return_date) }}</td>
                            <td class="mono">{{ $return->sale?->number ?: '—' }}</td>
                            <td class="num tnum font-semibold">{{ format_money($return->grand_total) }}</td>
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

        @if ($returns->count() >= $rowLimit)
            <div class="cust-tab-note">{{ __('customers.returns.capped', ['count' => number_format($rowLimit)]) }}</div>
        @endif
    @endif
</div>
