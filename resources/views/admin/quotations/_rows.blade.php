@foreach ($quotations as $quotation)
    @php
        $status = $quotation->effectiveStatus();
        $badge = match ($status) {
            'accepted', 'converted' => 'positive',
            'sent' => 'info',
            'expired' => 'warning',
            'declined', 'cancelled' => 'negative',
            default => 'muted',
        };
        $customerName = $quotation->customer_snapshot['business_name']
            ?? $quotation->customer_snapshot['name']
            ?? $quotation->customer?->name
            ?? __('quotations.walk_in');
    @endphp
    <tr class="prod-row" data-dt-row data-dt-name="{{ $quotation->displayNumber().' '.$customerName }}"
        @click="window.location.href = {{ \Illuminate\Support\Js::from(route('admin.quotations.show', $quotation)) }}">
        <td class="mono">{{ $quotation->displayNumber() }}</td>
        <td>{{ format_date($quotation->issue_date) }}</td>
        <td>{{ $quotation->valid_until ? format_date($quotation->valid_until) : '—' }}</td>
        <td>{{ $quotation->store?->name ?: '—' }}</td>
        <td>{{ $customerName }}</td>
        <td>{{ $quotation->creator?->name ?: '—' }}</td>
        <td><span class="prod-badge prod-badge-{{ $badge }}">{{ __('quotations.statuses.'.$status) }}</span></td>
        <td class="num tnum">{{ format_money($quotation->grand_total, null, $quotation->currency_code) }}</td>
        <td>
            <x-admin.row-actions>
                <x-admin.row-action :href="route('admin.quotations.show', $quotation)" icon="eye" :label="__('table.action.view')" />
                <x-admin.row-action :href="route('admin.quotations.document', $quotation)" target="_blank" icon="receipt" :label="__('quotations.actions.print')" />
                @can('update', $quotation)
                    <x-admin.row-action :href="route('admin.quotations.edit', $quotation)" icon="edit" :label="__('table.action.edit')" />
                @endcan
                @can('convert', $quotation)
                    <x-admin.row-action :href="route('admin.quotations.convert.form', $quotation)" icon="cart" :label="__('quotations.actions.convert')" />
                @endcan
            </x-admin.row-actions>
        </td>
    </tr>
@endforeach
