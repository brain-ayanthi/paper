<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" dir="{{ in_array(app()->getLocale(), ['ar', 'he', 'ur', 'fa']) ? 'rtl' : 'ltr' }}">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>{{ __('stock_takes.sheet.title', ['number' => $take->number]) }}</title>
    {{-- Inlined, like the product label sheet: this is a paper artifact
         rendered occasionally, not part of the admin bundle. --}}
    <style>{!! file_get_contents(resource_path('css/stock-take-sheet.css')) !!}</style>
</head>
<body>

{{-- Screen-only controls. --}}
<div class="cs-toolbar">
    <div class="cs-toolbar-title">{{ __('stock_takes.sheet.title', ['number' => $take->number]) }}</div>

    {{-- A counter who can see the expected quantity tends to write it down.
         A blind sheet is the only one that finds anything, so it's one click
         away rather than buried. --}}
    <a class="cs-btn"
       href="{{ route('admin.inventory.stock-takes.sheet', ['stockTake' => $take, 'blind' => $blind ? 0 : 1]) }}">
        {{ $blind ? __('stock_takes.sheet.show_expected') : __('stock_takes.sheet.blind') }}
    </a>
    <a class="cs-btn" href="{{ route('admin.inventory.stock-takes.show', $take) }}">
        {{ __('stock_takes.sheet.back') }}
    </a>
    <button type="button" class="cs-btn cs-btn-primary" onclick="window.print()">
        {{ __('stock_takes.sheet.print') }}
    </button>
</div>

<div class="cs-page">
    <div class="cs-head">
        <div>
            <h1 class="cs-title">{{ $take->number }}</h1>
            <div class="cs-sub">
                {{ $take->store?->name }}
                &middot; {{ format_date($take->take_date) }}
                @if ($take->name)
                    &middot; {{ $take->name }}
                @endif
            </div>
        </div>
        <div class="cs-signoff">
            {{ __('stock_takes.sheet.counted_by') }} <span></span><br>
            {{ __('stock_takes.sheet.date') }} <span></span>
        </div>
    </div>

    @forelse ($groups as $location => $items)
        <div class="cs-group">
            <div class="cs-group-head">
                <span>{{ $location !== '' ? $location : __('stock_takes.sheet.unplaced') }}</span>
                <span class="cs-group-count">{{ __('stock_takes.sheet.line_count', ['count' => $items->count()]) }}</span>
            </div>

            <table class="cs-table">
                <thead>
                    <tr>
                        <th>{{ __('stock_takes.items.product') }}</th>
                        @unless ($blind)
                            <th class="num">{{ __('stock_takes.items.expected') }}</th>
                        @endunless
                        <th class="num">{{ __('stock_takes.items.counted') }}</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($items as $item)
                        <tr>
                            <td>
                                <div>{{ $item->product?->name ?? '—' }}</div>
                                <div class="cs-sku">
                                    {{ $item->variant?->sku ?: $item->product?->sku }}
                                    @if ($item->variant?->label)
                                        &middot; {{ $item->variant->label }}
                                    @endif
                                </div>
                            </td>
                            @unless ($blind)
                                <td class="num">
                                    {{ rtrim(rtrim((string) $item->expected_quantity, '0'), '.') ?: '0' }}
                                    {{ $item->product?->unit?->code ?: 'pc' }}
                                </td>
                            @endunless
                            <td class="num"><div class="cs-write"></div></td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @empty
        <p>{{ __('stock_takes.empty_lines.title') }}</p>
    @endforelse

    <div class="cs-foot">
        <span>{{ __('stock_takes.sheet.total_lines', ['count' => $take->items->count()]) }}</span>
        <span>{{ config('app.name') }}</span>
    </div>
</div>

</body>
</html>
