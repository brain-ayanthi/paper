{{-- Returns tab — goods sent back to this supplier. Only posted returns
     appear; a draft return hasn't moved stock or money yet, so it isn't
     part of the supplier's history. --}}
<div class="card card-pad-0" x-data="dataTable({ rowsSelector: 'tbody > tr[data-dt-row]' })">
    <div class="dt-toolbar">
        <div class="dt-toolbar-title">
            {{ __('suppliers.returns.title') }}
            (<span x-text="rowCount">{{ $returns->count() }}</span>)
        </div>
        @if ($returns->isNotEmpty())
            <x-admin.dt-search :placeholder="__('suppliers.returns.search')" />
        @endif
        <div class="dt-toolbar-actions">
            <a href="{{ route('admin.purchase-returns.index', ['supplier_id' => $supplier->id]) }}"
               class="pos-btn pos-btn-xs pos-btn-ghost">
                {{ __('suppliers.returns.view_all') }}
            </a>
        </div>
    </div>

    @if ($returns->isEmpty())
        <div class="dt-empty">
            <span class="dt-empty-icon"><x-icon name="refund" class="w-5 h-5" /></span>
            <div class="dt-empty-title">{{ __('suppliers.returns.empty_title') }}</div>
            <div class="dt-empty-sub">{{ __('suppliers.returns.empty_sub') }}</div>
        </div>
    @else
        <div class="dt-scroll">
            <table class="dt-table">
                <thead>
                    <tr>
                        <th>{{ __('suppliers.returns.number') }}</th>
                        <th>{{ __('suppliers.returns.date') }}</th>
                        <th>{{ __('suppliers.returns.against') }}</th>
                        <th class="num">{{ __('suppliers.returns.amount') }}</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($returns as $return)
                        <tr class="prod-row" data-dt-row data-dt-name="{{ $return->number }} {{ $return->purchase?->number }}"
                            @click="window.location.href = {{ \Illuminate\Support\Js::from(route('admin.purchase-returns.show', $return)) }}">
                            <td class="mono">{{ $return->number }}</td>
                            <td>{{ format_date($return->return_date) }}</td>
                            <td class="mono">{{ $return->purchase?->number ?: '—' }}</td>
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
            <div class="cust-tab-note">{{ __('suppliers.returns.capped', ['count' => number_format($rowLimit)]) }}</div>
        @endif
    @endif
</div>
