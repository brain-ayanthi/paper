<div class="card card-pad-0">
    <div class="card-header"><div><div class="card-title">{{ __('quotations.title') }}</div><div class="card-title-sub">{{ __('quotations.sub') }}</div></div>
        @can('create', App\Models\Quotation::class)<a href="{{ route('admin.quotations.create', ['customer_id' => $customer->id]) }}" class="pos-btn pos-btn-sm pos-btn-ghost"><x-icon name="plus" class="w-4 h-4" />{{ __('quotations.actions.create') }}</a>@endcan
    </div>
    @if($quotations->isEmpty())
        <div class="dt-empty"><span class="dt-empty-icon"><x-icon name="note" class="w-5 h-5" /></span><div class="dt-empty-title">{{ __('quotations.empty.title') }}</div><div class="dt-empty-sub">{{ __('quotations.empty.sub') }}</div></div>
    @else
        <div class="dt-scroll"><table class="dt-table"><thead><tr><th>{{ __('quotations.columns.number') }}</th><th>{{ __('quotations.columns.issue_date') }}</th><th>{{ __('quotations.columns.valid_until') }}</th><th>{{ __('quotations.columns.store') }}</th><th>{{ __('quotations.columns.status') }}</th><th class="num">{{ __('quotations.columns.total') }}</th></tr></thead><tbody>
        @foreach($quotations as $quotation)<tr class="prod-row" @click="window.location.href = @js(route('admin.quotations.show', $quotation))"><td class="mono">{{ $quotation->displayNumber() }}</td><td>{{ format_date($quotation->issue_date) }}</td><td>{{ $quotation->valid_until ? format_date($quotation->valid_until) : '—' }}</td><td>{{ $quotation->store?->name }}</td><td><span class="prod-badge prod-badge-muted">{{ __('quotations.statuses.'.$quotation->effectiveStatus()) }}</span></td><td class="num tnum">{{ format_money($quotation->grand_total, null, $quotation->currency_code) }}</td></tr>@endforeach
        </tbody></table></div>
    @endif
</div>
