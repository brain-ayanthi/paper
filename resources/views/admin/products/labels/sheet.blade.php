<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>{{ __('labels.sheet.title') }}</title>
    {{-- Inline the base stylesheet (print-only page, rendered occasionally). --}}
    <style>{!! file_get_contents(resource_path('css/labels.css')) !!}</style>
    {{-- Per-layout geometry (mm) injected from the chosen layout.
         A `sheet` layout tiles labels across an A4 page; a `roll` layout makes
         the LABEL the page, so a thermal label printer feeds exactly one at a
         time with no sheet margins. --}}
    @php
        $isRoll = ($layout['type'] ?? 'sheet') === 'roll';
        $labelWidth = (float) $layout['label_w_mm'];
        $labelHeight = (float) $layout['label_h_mm'];
        $rollPaddingX = $labelWidth <= 30 ? 0.25 : ($labelWidth <= 40 ? 0.75 : 1.5);
        $rollPaddingY = $labelHeight <= 20 ? 0.5 : 0.8;
        $rollGap = $labelHeight <= 20 ? 0.35 : 0.6;
        $rollBarcodeHeight = round($labelHeight * 0.36, 1);
    @endphp
    <style>
        @page {
            size: {{ $isRoll ? $layout['label_w_mm'].'mm '.$layout['label_h_mm'].'mm' : 'A4 portrait' }};
            margin: 0;
        }
        .label-page {
            padding-top: {{ $layout['margin_top_mm'] }}mm;
            padding-left: {{ $layout['margin_left_mm'] }}mm;
        }
        @if ($isRoll)
        /* The page IS the label — override the A4 canvas from labels.css. */
        .label-page {
            width: {{ $layout['label_w_mm'] }}mm;
            min-height: {{ $layout['label_h_mm'] }}mm;
        }
        /* Keep barcode geometry independent from optional name/SKU/price rows. */
        .label-cell {
            gap: {{ $rollGap }}mm;
            padding: {{ $rollPaddingY }}mm {{ $rollPaddingX }}mm;
        }
        .label-barcode svg {
            height: {{ $rollBarcodeHeight }}mm;
            max-height: none;
        }
        @if ($labelHeight <= 20)
        .label-name {
            max-width: 100%;
            max-height: 1.2em;
            white-space: nowrap;
            text-overflow: ellipsis;
        }
        .label-sku { font-size: 6.5pt; }
        .label-price { font-size: 9pt; }
        @endif
        @endif
        .label-grid {
            grid-template-columns: repeat({{ (int) $layout['cols'] }}, {{ $layout['label_w_mm'] }}mm);
            column-gap: {{ $layout['col_gap_mm'] }}mm;
            row-gap: {{ $layout['row_gap_mm'] }}mm;
        }
        .label-cell {
            width: {{ $layout['label_w_mm'] }}mm;
            height: {{ $layout['label_h_mm'] }}mm;
        }
    </style>
</head>
<body>
    <div class="label-toolbar" role="toolbar">
        <a href="{{ route('admin.products.labels') }}" class="label-tool-btn">← {{ __('labels.sheet.back') }}</a>
        <span class="label-tool-spacer"></span>
        <span class="label-tool-count">{{ __('labels.sheet.count', ['count' => count($cells)]) }}</span>
        <button type="button" class="label-tool-btn label-tool-btn-primary" onclick="window.print()">
            🖨 {{ __('labels.sheet.print') }}
        </button>
    </div>

    @forelse ($pages as $page)
        <div class="label-page">
            <div class="label-grid">
                @foreach ($page as $cell)
                    <div class="label-cell">
                        @if ($fields['name'])
                            <div class="label-name">{{ $cell['name'] }}</div>
                        @endif
                        @if ($fields['barcode'] && $cell['barcode_svg'])
                            <div class="label-barcode">{!! $cell['barcode_svg'] !!}</div>
                        @endif
                        @if ($fields['sku'])
                            <div class="label-sku">{{ $cell['sku'] }}</div>
                        @endif
                        @if ($fields['price'])
                            <div class="label-price">{{ $cell['price'] }}</div>
                        @endif
                        {{-- Shelf location — makes a misplaced item visible
                             on the shelf itself, and tells restocking staff
                             where a stray product goes back. --}}
                        @if (($fields['location'] ?? false) && $cell['location'] !== '')
                            <div class="label-location">{{ $cell['location'] }}</div>
                        @endif
                    </div>
                @endforeach
            </div>
        </div>
    @empty
        <div class="label-page"><p style="padding:20mm;text-align:center;">{{ __('labels.sheet.empty') }}</p></div>
    @endforelse

    @if ($auto)
        <script data-auto-print>
            window.addEventListener('load', () => setTimeout(() => window.print(), 300));
        </script>
    @endif
</body>
</html>
