{{--
    Grid-view cards for the Products list.

    Same contract as `_rows.blade.php`: rendered inline for the first page,
    then fetched by `ProductController@rows` and *appended* to `.prod-grid`
    by the `dataTableServer` mixin as the user scrolls (infinite scroll).

    Cards carry the same `data-dt-*` attribute set as the table rows so
    `rowState` can be rebuilt from either view after a swap.

    Expects: $products (iterable of Product, with category/brand/unit
    loaded and variants_count / kit_items_count present).
--}}
@foreach ($products as $p)
    @php
        // Variant parents price their child SKUs, not themselves — show the
        // range, and let the data-dt-* attrs take the low end. Mirrors
        // `_rows.blade.php`.
        [$priceLow, $priceHigh] = $p->priceBounds();
        [$costLow,  $costHigh]  = $p->costBounds();
    @endphp
    <a href="{{ route('admin.products.edit', $p) }}"
       data-dt-row
       data-dt-product-id="{{ $p->id }}"
       data-dt-name="{{ $p->name }}"
       data-dt-sku="{{ $p->sku }}"
       data-dt-category="{{ optional($p->category)->name }}"
       data-dt-category-id="{{ $p->category_id }}"
       data-dt-brand="{{ optional($p->brand)->name }}"
       data-dt-type="{{ $p->type }}"
       data-dt-price="{{ $priceLow }}"
       data-dt-cost="{{ $costLow }}"
       data-dt-margin="{{ $priceLow > 0 ? round((($priceLow - $costLow) / $priceLow) * 100) : 0 }}"
       data-dt-active="{{ $p->is_active ? '1' : '0' }}"
       data-dt-featured="{{ $p->is_featured ? '1' : '0' }}"
       class="prod-card">
        <div class="prod-card-img">
            @if ($p->image_url)
                <img src="{{ $p->image_url }}" alt="" onerror="this.style.display='none'; this.nextElementSibling.style.display='flex'">
                <div class="prod-card-letter hidden">{{ \Illuminate\Support\Str::upper(\Illuminate\Support\Str::substr($p->name, 0, 1)) }}</div>
            @else
                <div class="prod-card-letter">{{ \Illuminate\Support\Str::upper(\Illuminate\Support\Str::substr($p->name, 0, 1)) }}</div>
            @endif
        </div>
        <div class="prod-card-body">
            <div class="prod-card-head">
                <div class="prod-card-name">{{ $p->name }}</div>
                {{-- Grid-card badge mirrors the table's state — bound to
                     the reactive rowState so the toggle button updates
                     this card in place even when the user switches view. --}}
                <template x-if="!rowState[{{ $p->id }}]?.is_active">
                    <span class="prod-badge prod-badge-muted">{{ __('products.list.inactive') }}</span>
                </template>
                <template x-if="rowState[{{ $p->id }}]?.is_active && rowState[{{ $p->id }}]?.is_featured">
                    <span class="prod-badge prod-badge-positive">{{ __('products.list.featured') }}</span>
                </template>
            </div>
            <div class="prod-card-meta">
                <span class="prod-card-sku mono">{{ $p->sku }}</span>
                @php
                    $typeCount = $p->type === 'variant' ? $p->variants_count
                        : ($p->type === 'kit' ? $p->kit_items_count : null);
                @endphp
                {{-- Type badge and the location trigger travel together on
                     the trailing side. Without this wrapper the meta row's
                     `space-between` spreads three children apart and the
                     location button floats off on its own. --}}
                <span class="prod-card-meta-end">
                    <span class="prod-type-badge is-{{ $p->type }}">
                        {{ __('products.list.type_'.$p->type) }}@if ($typeCount) · {{ $typeCount }}@endif
                    </span>
                    {{-- Icon-only, not the location text: a card is narrow,
                         and "A1 · R-12 · 2" truncates to "A1 · R…" — worse
                         than useless, since a half-shown rack sends someone
                         to the wrong shelf. The modal has room for them all. --}}
                    @php $locationList = product_location_list($p); @endphp
                    @if ($locationList !== [])
                        <button type="button"
                                class="prod-card-loc-btn"
                                @click.stop.prevent="openLocations({{ \Illuminate\Support\Js::from($p->name) }}, {{ \Illuminate\Support\Js::from($locationList) }})"
                                title="{{ __('products.locations.details.open') }}"
                                aria-label="{{ __('products.locations.details.open') }}">
                            <x-icon name="box" class="w-3 h-3" />
                            @if (count($locationList) > 1)
                                <span>{{ count($locationList) }}</span>
                            @endif
                        </button>
                    @endif
                </span>
            </div>
            <div class="prod-card-foot">
                <span class="prod-card-price tnum">{{ format_money_range($priceLow, $priceHigh) }}</span>
                <span class="prod-card-cost tnum prod-muted">
                    {{ __('products.list.cost_short') }}
                    {{ format_money_range($costLow, $costHigh) }}
                </span>
            </div>
        </div>
    </a>
@endforeach
