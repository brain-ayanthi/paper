{{--
    Table-view rows for the Stock movements ledger.

    Rendered inline by `admin.inventory.movements.index` (first page) and
    standalone by `StockMovementController@rows` (JSON `html`) for every
    subsequent page / filter / search. Read-only ledger rows.

    Each row links to the document that caused the movement (purchase, sale,
    adjustment, transfer…) — resolved by `StockMovementReference`, which
    returns a null url when the document is gone or the viewer may not open
    it. Opening-balance rows have no reference and stay unclickable.

    Expects: $movements (iterable of StockMovement, with product/variant/store/
    creator/reference loaded).
--}}
@foreach ($movements as $m)
    @php
        $delta = (float) $m->quantity_delta;
        $ref   = \App\Support\StockMovementReference::for($m);
    @endphp
    <tr data-dt-row
        data-dt-name="{{ $m->product?->name ?? '' }}"
        data-dt-id="{{ $m->id }}"
        @class(['inv-mv-row-static' => empty($ref['url'])])
        @if (! empty($ref['url']))
            @click="window.location.href = {{ \Illuminate\Support\Js::from($ref['url']) }}"
        @endif>
        <td title="{{ format_datetime($m->created_at) }}">{{ $m->created_at?->diffForHumans() }}</td>
        <td>
            <div class="store-row-name-line">
                <span class="store-row-name">{{ $m->product?->name }}</span>
                @if ($m->variant) <span class="fg-tertiary">· {{ $m->variant->label }}</span> @endif
            </div>
            <span class="mono fg-tertiary">{{ $m->variant?->sku ?? $m->product?->sku }}</span>
        </td>
        <td>{{ $m->store?->name }}</td>
        <td>
            <span class="prod-badge prod-badge-muted">{{ __("inventory.movements.types.{$m->type}") }}</span>
        </td>
        <td class="num tnum {{ $delta >= 0 ? 'inv-delta-pos' : 'inv-delta-neg' }}">
            {{ $delta >= 0 ? '+' : '' }}{{ rtrim(rtrim(number_format($delta, 4, '.', ''), '0'), '.') ?: '0' }}
        </td>
        <td class="num tnum">{{ rtrim(rtrim(number_format((float) $m->quantity_after, 4, '.', ''), '0'), '.') ?: '0' }}</td>
        <td class="num tnum fg-tertiary">{{ $m->unit_cost !== null ? format_money((float) $m->unit_cost) : '—' }}</td>
        <td class="mono fg-tertiary">
            @if (! $ref)
                —
            @elseif ($ref['url'])
                <a href="{{ $ref['url'] }}" class="inv-mv-ref" @click.stop>{{ $ref['label'] }}</a>
            @else
                {{ $ref['label'] }}
            @endif
        </td>
        <td>{{ $m->creator?->name ?? '—' }}</td>
    </tr>
@endforeach
