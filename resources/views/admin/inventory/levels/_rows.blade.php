{{--
    Table-view rows for the Stock levels list.

    Rendered inline by `admin.inventory.levels.index` (first page) and standalone
    by `StockLevelController@rows` (JSON `html`) for every subsequent page /
    filter / search. Each row carries an inline reorder-override edit
    (`data-ajax-form`), which submits independently of pagination.

    Expects: $levels (iterable of StockLevel, with product/variant/store loaded).
--}}
@foreach ($levels as $level)
    @php
        $product    = $level->product;
        $variant    = $level->variant;
        $qty        = (float) $level->quantity;
        $reorder    = $level->reorder_level_override ?? $product?->reorder_level;
        $isLow      = $reorder !== null && $qty <= (float) $reorder;
        $isNegative = $qty < 0;
        // Pick face at THIS row's store — the list can span stores, so the
        // location has to follow the row, not the active store.
        $location   = $product?->primaryLocation($level->store_id)?->label() ?: '';
        $batches    = $product?->track_batches && $level->relationLoaded('batches')
            ? $level->getRelation('batches')
            : collect();
        $batchQty   = $batches->reduce(
            fn (string $total, $batch) => bcadd($total, (string) $batch->quantity, 4),
            '0.0000',
        );
        $unallocated = bcsub((string) $level->quantity, $batchQty, 4);
        $showBatchPrices = $batchPricing ?? false;
        $formatQty = fn ($value) => rtrim(rtrim(number_format((float) $value, 4, '.', ''), '0'), '.') ?: '0';
    @endphp
    <tr data-dt-row
        data-dt-name="{{ $product?->name ?? '' }} {{ $location }}"
        data-dt-id="{{ $level->id }}">
        <td>
            <div class="store-row-name-line">
                <span class="store-row-name">{{ $product?->name ?? '—' }}</span>
                @if ($variant) <span class="fg-tertiary">· {{ $variant->label }}</span> @endif
                @if ($isNegative)
                    <span class="prod-badge prod-badge-danger">{{ __('inventory.levels.badges.negative') }}</span>
                @elseif ($isLow)
                    <span class="prod-badge prod-badge-warning">{{ __('inventory.levels.badges.low') }}</span>
                @endif
            </div>
        </td>
        <td class="mono">{{ $variant?->sku ?? $product?->sku }}</td>
        <td>{{ $level->store?->name }}</td>
        <td>
            @if ($location !== '')
                <span class="prod-loc-chip">{{ $location }}</span>
            @else
                <span class="fg-tertiary">—</span>
            @endif
        </td>
        <td class="num tnum">{{ rtrim(rtrim(number_format($qty, 4, '.', ''), '0'), '.') ?: '0' }}</td>
        <td class="num tnum fg-tertiary">{{ rtrim(rtrim(number_format((float) $level->reserved_quantity, 4, '.', ''), '0'), '.') ?: '0' }}</td>
        {{-- Weighted-average cost — updated by every positive costed movement. --}}
        <td class="num tnum">{{ (float) $level->weighted_average_cost > 0 ? format_money($level->weighted_average_cost) : '—' }}</td>
        <td class="num">
            <form method="POST" action="{{ route('admin.inventory.levels.reorder', $level) }}" class="inline-flex" data-ajax-form>
                @csrf
                @method('PATCH')
                <input type="number" name="reorder_level_override"
                       value="{{ $level->reorder_level_override !== null ? rtrim(rtrim(number_format((float) $level->reorder_level_override, 4, '.', ''), '0'), '.') : '' }}"
                       step="any" min="0"
                       class="pos-input pos-input-sm inv-reorder-input tnum"
                       placeholder="{{ $product?->reorder_level !== null ? number_format((float) $product->reorder_level, 0) : '—' }}"
                       @change="$el.form.requestSubmit()">
            </form>
        </td>
        <td>{{ $level->last_movement_at?->diffForHumans() ?? '—' }}</td>
    </tr>
    @if ($product?->track_batches)
        <tr class="inv-level-batch-row">
            <td colspan="9">
                <div class="inv-level-batch-panel">
                    <div class="inv-level-batch-heading">
                        <span>{{ __('inventory.levels.batches.title', ['count' => $batches->count()]) }}</span>
                        @if (bccomp($unallocated, '0', 4) !== 0)
                            <span class="prod-badge prod-badge-warning">
                                {{ __('inventory.levels.batches.unallocated', ['quantity' => $formatQty($unallocated)]) }}
                            </span>
                        @endif
                    </div>

                    @if ($batches->isEmpty() && bccomp($unallocated, '0', 4) === 0)
                        <p class="inv-level-batch-empty">{{ __('inventory.levels.batches.missing') }}</p>
                    @else
                        <div class="inv-level-batch-scroll">
                            <table class="inv-level-batch-table">
                                <thead>
                                    <tr>
                                        <th>{{ __('inventory.levels.batches.number') }}</th>
                                        <th>{{ __('inventory.levels.batches.manufactured') }}</th>
                                        <th>{{ __('inventory.levels.batches.expiry') }}</th>
                                        <th class="num">{{ __('inventory.levels.batches.quantity') }}</th>
                                        @if ($showBatchPrices)
                                            <th class="num">{{ __('inventory.levels.batches.cost') }}</th>
                                            <th class="num">{{ __('inventory.levels.batches.selling_price') }}</th>
                                            <th class="num">{{ __('inventory.levels.batches.mrp') }}</th>
                                        @endif
                                    </tr>
                                </thead>
                                <tbody>
                                    @foreach ($batches as $batch)
                                        <tr>
                                            <td>
                                                <span class="mono font-medium">{{ $batch->batch_number }}</span>
                                                @if ($batch->isExpired())
                                                    <span class="prod-badge prod-badge-danger ms-1">{{ __('inventory.levels.batches.expired') }}</span>
                                                @endif
                                            </td>
                                            <td>{{ $batch->manufacture_date ? format_date($batch->manufacture_date) : '—' }}</td>
                                            <td>{{ $batch->expiry_date ? format_date($batch->expiry_date) : '—' }}</td>
                                            <td class="num tnum font-semibold">{{ $formatQty($batch->quantity) }}</td>
                                            @if ($showBatchPrices)
                                                <td class="num tnum">{{ $batch->cost_price !== null ? format_money($batch->cost_price) : '—' }}</td>
                                                <td class="num tnum">{{ $batch->selling_price !== null ? format_money($batch->selling_price) : '—' }}</td>
                                                <td class="num tnum">{{ $batch->mrp !== null ? format_money($batch->mrp) : '—' }}</td>
                                            @endif
                                        </tr>
                                    @endforeach
                                    @if (bccomp($unallocated, '0', 4) !== 0)
                                        <tr>
                                            <td><span class="font-medium">{{ __('inventory.levels.batches.unallocated_label') }}</span></td>
                                            <td>â€”</td>
                                            <td>â€”</td>
                                            <td class="num tnum font-semibold">{{ $formatQty($unallocated) }}</td>
                                            @if ($showBatchPrices)
                                                <td class="num tnum">{{ format_money($level->weighted_average_cost) }}</td>
                                                <td class="num tnum">â€”</td>
                                                <td class="num tnum">â€”</td>
                                            @endif
                                        </tr>
                                    @endif
                                </tbody>
                            </table>
                        </div>
                    @endif
                </div>
            </td>
        </tr>
    @endif
@endforeach
