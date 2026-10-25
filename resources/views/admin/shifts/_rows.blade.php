{{--
    Table-view rows for the Shifts list.

    Rendered inline by `admin.shifts.index` (first page) and standalone by
    `ShiftController@rows` (JSON `html`) for every subsequent page / search /
    sort. Read-only rows — the whole row links to the shift detail.

    Expects: $shifts (iterable of Shift, with store + cashier loaded).
--}}
@foreach ($shifts as $shift)
    <tr class="prod-row" data-dt-row
        data-dt-name="#{{ $shift->id }} {{ $shift->cashier?->name }} {{ $shift->terminal?->name }} {{ $shift->terminal?->code }}"
        data-dt-id="{{ $shift->id }}"
        data-dt-status="{{ $shift->status }}"
        @click="window.location.href = {{ \Illuminate\Support\Js::from(route('admin.shifts.show', $shift)) }}">
        <td class="mono">#{{ $shift->id }}</td>
        <td>{{ $shift->cashier?->name ?: '—' }}</td>
        <td>
            @if ($shift->terminal)
                {{ $shift->terminal->name }}
                @if ($shift->terminal->code)
                    <span class="mono text-xs fg-tertiary">({{ $shift->terminal->code }})</span>
                @endif
            @else
                —
            @endif
        </td>
        <td>{{ format_datetime($shift->opened_at) }}</td>
        <td>{{ $shift->closed_at ? format_datetime($shift->closed_at) : '—' }}</td>
        <td class="num tnum">{{ $shift->isClosed() ? format_money($shift->sales_total) : '—' }}</td>
        <td class="num tnum">
            @if ($shift->cash_variance !== null && $shift->isClosed())
                @php $vCmp = bccomp((string) $shift->cash_variance, '0', 4); @endphp
                <span @class([
                    'mono',
                    'text-amber-600 dark:text-amber-400' => $vCmp !== 0,
                ])>{{ format_money($shift->cash_variance) }}</span>
            @else
                —
            @endif
        </td>
        <td>
            @php
                $badge = match ($shift->status) {
                    'open'                  => 'info',
                    'closed'                => 'positive',
                    'closed_with_variance'  => 'warning',
                    default                 => 'muted',
                };
            @endphp
            <span class="prod-badge prod-badge-{{ $badge }}">
                {{ __('shifts.statuses.'.$shift->status) }}
            </span>
            {{-- Stale flag: an open shift older than ~a working day is likely
                 abandoned (cashier logged out without closing). --}}
            @if ($shift->isOpen())
                @php $hoursOpen = (int) $shift->opened_at->diffInHours(now()); @endphp
                @if ($hoursOpen >= 8)
                    <span class="prod-badge prod-badge-warning ms-1" title="{{ __('shifts.stale.tooltip') }}">
                        {{ __('shifts.stale.label', ['hours' => $hoursOpen]) }}
                    </span>
                @endif
            @endif
        </td>
    </tr>
@endforeach
