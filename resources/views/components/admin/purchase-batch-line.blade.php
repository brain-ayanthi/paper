@props([
    'number' => null,
    'mfg'    => null,
    'expiry' => null,
    'selling' => null,
    'mrp'     => null,
])

{{-- Compact batch / mfg / expiry sub-line for purchase + return item rows.
     Renders nothing when no batch info was captured on the line. --}}
@if ($number || $mfg || $expiry || $selling || $mrp)
    <div class="fg-tertiary text-xs flex flex-wrap gap-x-2 mt-0.5">
        @if ($number)
            <span>{{ __('purchases.line.batch_chip') }}: <span class="mono">{{ $number }}</span></span>
        @endif
        @if ($mfg)
            <span>{{ __('purchases.line.mfg_short') }}: {{ \Illuminate\Support\Carbon::parse($mfg)->format('d M Y') }}</span>
        @endif
        @if ($expiry)
            <span>{{ __('purchases.line.exp_short') }}: {{ \Illuminate\Support\Carbon::parse($expiry)->format('d M Y') }}</span>
        @endif
        @if ($selling !== null)
            <span>{{ __('purchases.line.batch_selling_price') }}: {{ format_money($selling) }}</span>
        @endif
        @if ($mrp !== null)
            <span>{{ __('purchases.line.batch_mrp') }}: {{ format_money($mrp) }}</span>
        @endif
    </div>
@endif
