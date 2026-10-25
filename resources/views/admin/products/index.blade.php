<x-admin-layout
    active="products"
    :title="__('products.title')"
    :crumbs="[
        ['label' => __('admin.shell.brand_sub')],
        ['label' => __('products.crumb_parent')],
        ['label' => __('products.title')],
    ]">

    {{-- Server-paginated. Only the first page of the active view is rendered
         below; every search / filter / sort / page fetches one page of rows
         from `admin.products.rows`. See resources/js/admin/data-table-server.js. --}}
    <div class="page-wide" x-data="productsPage({{ \Illuminate\Support\Js::from([
        'endpoint'      => route('admin.products.rows'),
        'view'          => $view,
        'perPage'       => $perPage,
        'tablePageSize' => $tablePageSize,
        'gridPageSize'  => $gridPageSize,
        'total'        => $summary['total'],
        'page'         => 1,
        'totalPages'   => max(1, (int) ceil($summary['total'] / max(1, $perPage))),
        'grandTotal'   => $grandTotal,
        'summary'      => $summary,
        // Blank when the corresponding permission is absent. Selection is
        // available when either bulk action can be used.
        'bulkLocationUrl' => auth()->user()?->can('products.update')
            ? route('admin.products.bulk-location')
            : '',
        'bulkDeleteUrl' => auth()->user()?->can('products.delete')
            ? route('admin.products.bulk-delete')
            : '',
        'bulkDeleteText' => [
            'title'     => __('products.locations.bulk.delete_title'),
            'warning'   => __('products.locations.bulk.delete_warning'),
            'pageOnly'  => __('products.locations.bulk.delete_page_only'),
            'confirm'   => __('products.locations.bulk.delete_confirm'),
            'cancel'    => __('products.actions.cancel'),
        ],
    ]) }})">

        {{-- ── Page header (mirrors design-system/admin/Products.html) ── --}}
        <div class="page-header mb-4">
            <div>
                <h1 class="page-title">{{ __('products.title') }}</h1>
                <p class="page-sub">
                    {{ __('products.list.header_sub', ['count' => $grandTotal]) }}
                </p>
            </div>
            <div class="flex flex-wrap items-center gap-2">
                <div class="dropdown" x-data="dropdown">
                    <button type="button"
                            class="pos-btn pos-btn-sm pos-btn-ghost"
                            @click="toggle()"
                            :aria-expanded="open">
                        <x-icon name="download" class="w-4 h-4" />
                        {{ __('products.actions.export') }}
                        <x-icon name="chevron" class="w-4 h-4" />
                    </button>
                    <div class="dropdown-panel"
                         x-show="open"
                         x-cloak
                         @click.outside="close()"
                         @keydown.escape.window="close()">
                        <a href="{{ route('admin.products.export', ['format' => 'csv']) }}"
                           class="dropdown-item"
                           @click="close()">
                            <span class="dropdown-item-label">{{ __('products.actions.export_csv') }}</span>
                        </a>
                        <a href="{{ route('admin.products.export', ['format' => 'xlsx']) }}"
                           class="dropdown-item"
                           @click="close()">
                            <span class="dropdown-item-label">{{ __('products.actions.export_xlsx') }}</span>
                        </a>
                    </div>
                </div>

                <a href="{{ route('admin.products.import') }}"
                   class="pos-btn pos-btn-sm pos-btn-ghost">
                    <x-icon name="archive" class="w-4 h-4" />
                    {{ __('products.actions.import') }}
                </a>

                <a href="{{ route('admin.products.bulk-images') }}"
                   class="pos-btn pos-btn-sm pos-btn-ghost">
                    <x-icon name="image" class="w-4 h-4" />
                    {{ __('products.bulk_images.action') }}
                </a>

                <a href="{{ route('admin.products.create') }}" class="pos-btn pos-btn-sm pos-btn-primary">
                    <x-icon name="plus" class="w-4 h-4" />
                    {{ __('products.actions.new') }}
                </a>
            </div>
        </div>

        <x-admin.summary-cards :cards="$summaryCards" />

        {{-- "No products at all" — distinct from "no rows match the filters",
             which the data-table's own `.dt-empty` handles. Keyed on the
             unfiltered count so it never shows just because a filter is on. --}}
        @if ($grandTotal === 0)
            <div class="card card-pad-0">
                <div class="dt-empty">
                    <span class="dt-empty-icon"><x-icon name="box" class="w-5 h-5" /></span>
                    <div class="dt-empty-title">{{ __('products.list.empty_title') }}</div>
                    <div class="dt-empty-sub">{{ __('products.list.empty_sub') }}</div>
                </div>
            </div>
        @else
            {{-- ── Toolbar row ─────────────────────────────────────── --}}
            <div class="prod-toolbar mb-3">
                <div class="prod-toolbar-end">
                    <button type="button"
                            class="pos-btn pos-btn-sm pos-btn-ghost"
                            :disabled="_dtRefreshing"
                            @click="dtRefresh()"
                            :title="@js(__('products.list.refresh'))">
                        <span class="inline-flex" :class="{ 'animate-spin': _dtRefreshing }">
                            <x-icon name="refresh" class="w-4 h-4" />
                        </span>
                    </button>

                    <span class="prod-toolbar-count">
                        <span x-text="matchedCount.toLocaleString()"></span>
                        {{ __('products.list.of') }}
                        <span>{{ number_format($grandTotal) }}</span>
                    </span>

                    <div class="seg" role="tablist" aria-label="{{ __('products.list.view_label') }}">
                        <button type="button"
                                role="tab"
                                :aria-selected="view === 'table'"
                                :class="{ 'is-active': view === 'table' }"
                                @click="setView('table')">
                            <x-icon name="list" class="w-3.5 h-3.5" />
                            {{ __('products.list.view_table') }}
                        </button>
                        <button type="button"
                                role="tab"
                                :aria-selected="view === 'grid'"
                                :class="{ 'is-active': view === 'grid' }"
                                @click="setView('grid')">
                            <x-icon name="dashboard" class="w-3.5 h-3.5" />
                            {{ __('products.list.view_grid') }}
                        </button>
                    </div>
                </div>
            </div>

            {{-- ── Filter bar (shared — applies to both table + grid views) ── --}}
            <div class="inv-filter inv-filter--boxed mb-3">
                {{-- Search --}}
                <label class="field inv-filter-search">
                    <span class="field-label">{{ __('table.search_label') }}</span>
                    <div class="inv-search">
                        <span class="inv-search-icon"><x-icon name="search" class="w-4 h-4" /></span>
                        {{-- No `.debounce` modifier: the mixin debounces the
                             fetch itself, and stacking the two would add up. --}}
                        <input type="search"
                               x-model="search"
                               placeholder="{{ __('products.list.search_placeholder') }}"
                               class="pos-input">
                    </div>
                </label>

                {{-- Category --}}
                <label class="field flex-none basis-[200px]">
                    <span class="field-label">{{ __('products.list.col_category') }}</span>
                    <select class="pos-input"
                            x-data="enhancedSelect()"
                            x-model="categoryFilter"
                            x-effect="ts && ts.setValue(categoryFilter)">
                        <option value="all">{{ __('products.list.filter_cat_all') }}</option>
                        @foreach ($filterCategories as $c)
                            <option value="{{ $c->id }}">{{ $c->name }}</option>
                        @endforeach
                    </select>
                </label>

                {{-- Type --}}
                <label class="field inv-filter-select">
                    <span class="field-label">{{ __('products.list.col_type') }}</span>
                    <select class="pos-input"
                            x-data="enhancedSelect()"
                            x-model="typeFilter"
                            x-effect="ts && ts.setValue(typeFilter)">
                        <option value="all">{{ __('products.list.filter_type_all') }}</option>
                        <option value="simple">{{ __('products.list.type_simple') }}</option>
                        <option value="variant">{{ __('products.list.type_variant') }}</option>
                        <option value="kit">{{ __('products.list.type_kit') }}</option>
                    </select>
                </label>

                {{-- Status --}}
                <label class="field inv-filter-select">
                    <span class="field-label">{{ __('products.list.col_status') }}</span>
                    <select class="pos-input"
                            x-data="enhancedSelect()"
                            x-model="activeFilter"
                            x-effect="ts && ts.setValue(activeFilter)">
                        <option value="all">{{ __('products.list.filter_status_all') }}</option>
                        <option value="active">{{ __('products.list.filter_status_active') }}</option>
                        <option value="inactive">{{ __('products.list.filter_status_inactive') }}</option>
                    </select>
                </label>

                {{-- Shelf location — "which products still have nowhere to
                     stand?" is the question that drives the first data-entry
                     pass after turning locations on. --}}
                <label class="field inv-filter-select">
                    <span class="field-label">{{ __('products.list.col_location') }}</span>
                    <select class="pos-input"
                            x-data="enhancedSelect()"
                            x-model="locatedFilter"
                            x-effect="ts && ts.setValue(locatedFilter)">
                        <option value="all">{{ __('products.list.filter_located_all') }}</option>
                        <option value="yes">{{ __('products.list.filter_located_yes') }}</option>
                        <option value="no">{{ __('products.list.filter_located_no') }}</option>
                    </select>
                </label>

                {{-- Featured --}}
                <label class="field inv-filter-select">
                    <span class="field-label">{{ __('products.list.featured') }}</span>
                    <select class="pos-input"
                            x-data="enhancedSelect()"
                            x-model="featuredFilter"
                            x-effect="ts && ts.setValue(featuredFilter)">
                        <option value="all">{{ __('products.list.filter_featured_all') }}</option>
                        <option value="featured">{{ __('products.list.filter_featured_only') }}</option>
                    </select>
                </label>

                {{-- Reset --}}
                <button type="button"
                        class="inv-filter-reset"
                        @click="resetFilters()">
                    <x-icon name="x" class="w-3.5 h-3.5" />
                    <span>{{ __('table.filter_reset') }}</span>
                </button>
            </div>

            {{-- ── Bulk selection bar ──────────────────────────────────
                 Appears once rows are ticked. Table view only — ticking
                 boxes in a card grid is fiddly, and the grid is the
                 browsing view, not the data-entry one.

                 The "select every match" affordance matters more than it
                 looks: with server-side paging, ticking the header box
                 selects 25 products, and a shop entering shelf locations
                 needs to act on all 340 syrups at once. --}}
            @if (auth()->user()?->can('products.update') || auth()->user()?->can('products.delete'))
                <div class="prod-bulk-bar" x-show="view === 'table' && selectedCount > 0" x-cloak>
                    <span class="prod-bulk-count"
                          x-text="allFilteredSelected
                              ? @js(__('products.locations.bulk.all_selected')).replace(':count', grandTotalLabel)
                              : @js(__('products.locations.bulk.n_selected')).replace(':count', selectedCount)"></span>

                    {{-- Offered only when the page is fully ticked and there
                         is more beyond it — otherwise it's noise. --}}
                    <button type="button"
                            class="pos-btn pos-btn-sm pos-btn-ghost"
                            x-show="canSelectAllFiltered"
                            x-cloak
                            @click="selectAllFiltered()">
                        <span x-text="@js(__('products.locations.bulk.select_all_filtered')).replace(':count', grandTotalLabel)"></span>
                    </button>

                    <div class="prod-bulk-spacer"></div>

                    @can('products.update')
                        <button type="button" class="pos-btn pos-btn-sm pos-btn-primary" @click="openLocationModal('set')">
                            <x-icon name="box" class="w-4 h-4" />
                            <span>{{ __('products.locations.bulk.set_action') }}</span>
                        </button>
                        <button type="button" class="pos-btn pos-btn-sm pos-btn-ghost" @click="openLocationModal('clear')">
                            <span>{{ __('products.locations.bulk.clear_action') }}</span>
                        </button>
                    @endcan
                    @can('products.delete')
                        <button type="button"
                                class="pos-btn pos-btn-sm pos-btn-ghost pos-btn-danger"
                                :disabled="allFilteredSelected"
                                :title="allFilteredSelected ? bulkDeleteText.pageOnly : ''"
                                @click="confirmBulkDelete()">
                            <x-icon name="trash" class="w-4 h-4" />
                            <span>{{ __('products.locations.bulk.delete_action') }}</span>
                        </button>
                    @endcan
                    <button type="button" class="pos-btn pos-btn-sm pos-btn-ghost" @click="clearSelection()">
                        <x-icon name="x" class="w-3.5 h-3.5" />
                        <span>{{ __('products.locations.bulk.deselect') }}</span>
                    </button>
                </div>
            @endif

            {{-- ── Table view ──────────────────────────────────────── --}}
            {{-- Rendered inline only when it's the active view; otherwise the
                 tbody starts empty and `setView('table')` fetches page 1.
                 `x-cloak` on the inactive view stops it flashing an empty
                 container before Alpine's x-show resolves. --}}
            <div x-show="view === 'table'" @if ($view !== 'table') x-cloak @endif>
                <x-admin.data-table :show-search="false" :show-toolbar="false">
                    <div class="dt-scroll">
                    <table class="dt-table">
                        <thead>
                            <tr>
                                @if (auth()->user()?->can('products.update') || auth()->user()?->can('products.delete'))
                                    {{-- Ticks every row on THIS page. The bulk bar
                                         then offers to extend to the whole filtered
                                         set, which is the selection that scales. --}}
                                    <th class="prod-check-col">
                                        <input type="checkbox"
                                               class="pos-check"
                                               :checked="pageFullySelected"
                                               :indeterminate="selectedCount > 0 && !pageFullySelected"
                                               @change="togglePageSelection($event.target.checked)"
                                               aria-label="{{ __('products.locations.bulk.select_page') }}">
                                    </th>
                                @endif
                                <th class="dt-th-sort"
                                    @click="sortBy('name')"
                                    :class="{ 'is-sorted-asc': sortDirFor('name') === 'asc', 'is-sorted-desc': sortDirFor('name') === 'desc' }">
                                    {{ __('products.list.col_name') }}
                                    <span class="dt-th-sort-arrow" aria-hidden="true"></span>
                                </th>
                                <th class="dt-th-sort"
                                    @click="sortBy('sku')"
                                    :class="{ 'is-sorted-asc': sortDirFor('sku') === 'asc', 'is-sorted-desc': sortDirFor('sku') === 'desc' }">
                                    {{ __('products.list.col_sku') }}
                                    <span class="dt-th-sort-arrow" aria-hidden="true"></span>
                                </th>
                                <th class="dt-th-sort"
                                    @click="sortBy('category')"
                                    :class="{ 'is-sorted-asc': sortDirFor('category') === 'asc', 'is-sorted-desc': sortDirFor('category') === 'desc' }">
                                    {{ __('products.list.col_category') }}
                                    <span class="dt-th-sort-arrow" aria-hidden="true"></span>
                                </th>
                                <th class="dt-th-sort"
                                    @click="sortBy('type')"
                                    :class="{ 'is-sorted-asc': sortDirFor('type') === 'asc', 'is-sorted-desc': sortDirFor('type') === 'desc' }">
                                    {{ __('products.list.col_type') }}
                                    <span class="dt-th-sort-arrow" aria-hidden="true"></span>
                                </th>
                                {{-- Shelf location at the active store. Sorts on
                                     the zero-padded walk key, so the order
                                     matches the order you'd walk the aisles. --}}
                                <th class="dt-th-sort"
                                    @click="sortBy('location')"
                                    :class="{ 'is-sorted-asc': sortDirFor('location') === 'asc', 'is-sorted-desc': sortDirFor('location') === 'desc' }">
                                    {{ __('products.list.col_location') }}
                                    <span class="dt-th-sort-arrow" aria-hidden="true"></span>
                                </th>
                                <th class="num dt-th-sort"
                                    @click="sortBy('price')"
                                    :class="{ 'is-sorted-asc': sortDirFor('price') === 'asc', 'is-sorted-desc': sortDirFor('price') === 'desc' }">
                                    {{ __('products.list.col_price') }}
                                    <span class="dt-th-sort-arrow" aria-hidden="true"></span>
                                </th>
                                <th class="num dt-th-sort"
                                    @click="sortBy('cost')"
                                    :class="{ 'is-sorted-asc': sortDirFor('cost') === 'asc', 'is-sorted-desc': sortDirFor('cost') === 'desc' }">
                                    {{ __('products.list.col_cost') }}
                                    <span class="dt-th-sort-arrow" aria-hidden="true"></span>
                                </th>
                                <th class="num dt-th-sort"
                                    @click="sortBy('margin')"
                                    :class="{ 'is-sorted-asc': sortDirFor('margin') === 'asc', 'is-sorted-desc': sortDirFor('margin') === 'desc' }">
                                    {{ __('products.list.col_margin') }}
                                    <span class="dt-th-sort-arrow" aria-hidden="true"></span>
                                </th>
                                <th class="dt-th-sort"
                                    @click="sortBy('active')"
                                    :class="{ 'is-sorted-asc': sortDirFor('active') === 'asc', 'is-sorted-desc': sortDirFor('active') === 'desc' }">
                                    {{ __('products.list.col_status') }}
                                    <span class="dt-th-sort-arrow" aria-hidden="true"></span>
                                </th>
                                <th>{{ __('products.list.col_actions') }}</th>
                            </tr>
                        </thead>
                        <tbody data-dt-rows="table">
                            @if ($view === 'table')
                                @include('admin.products._rows', ['products' => $products])
                            @endif
                        </tbody>
                    </table>
                    </div>
                </x-admin.data-table>
            </div>

            {{-- ── Grid view ───────────────────────────────────────── --}}
            {{-- Grid cards carry the same `data-dt-row` + data-dt-* set as the
                 table rows, so `rowState` rebuilds from either view. Only the
                 active view's rows are ever in the DOM. --}}
            <div x-show="view === 'grid'" @if ($view !== 'grid') x-cloak @endif>
                <div class="prod-grid" data-dt-rows="grid">
                    @if ($view === 'grid')
                        @include('admin.products._cards', ['products' => $products])
                    @endif
                </div>

                {{-- No matches — the grid has no `<x-admin.data-table>` chrome,
                     so it carries its own empty state. --}}
                <div class="dt-empty" x-show="dtReady && isEmpty" x-cloak>
                    <span class="dt-empty-icon"><x-icon name="search" class="w-5 h-5" /></span>
                    <div class="dt-empty-title">{{ __('table.empty.no_results_title') }}</div>
                    <div class="dt-empty-sub">{{ __('table.empty.no_results_sub') }}</div>
                </div>

                {{-- Infinite-scroll sentinel: when it nears the viewport the grid
                     fetches and appends the next server page (products-page.js). --}}
                <div x-ref="gridSentinel" class="prod-grid-sentinel" aria-hidden="true"></div>
            </div>
        @endif

        {{-- ══════════ Location details ══════════
             A product can occupy four places at one store (front rack,
             godown, fridge, locked cabinet). Neither a table cell nor a
             grid card can show that, so both open this instead. --}}
        <div class="scrim overlay-host" x-show="locationsPanel" x-cloak
             @click.self="locationsPanel = false"
             @keydown.escape.window="locationsPanel = false">
            <div class="modal-card prod-loc-modal" role="dialog" aria-modal="true">
                <div class="modal-head">
                    <div>
                        <div class="modal-title">{{ __('products.locations.details.title') }}</div>
                        <div class="modal-sub" x-text="locationsProduct"></div>
                    </div>
                    <button type="button" class="modal-x" @click="locationsPanel = false"
                            aria-label="{{ __('products.actions.cancel') }}">
                        <x-icon name="x" class="w-4 h-4" />
                    </button>
                </div>

                <div class="modal-body">
                    <ul class="prod-loc-list">
                        <template x-for="(loc, i) in locationsRows" :key="i">
                            <li class="prod-loc-list-item" :class="{ 'is-primary': loc.is_primary }">
                                <div class="prod-loc-list-head">
                                    <span class="prod-loc-chip" x-text="loc.label || '—'"></span>
                                    <span class="prod-loc-list-kind" x-text="loc.kind"></span>
                                    <template x-if="loc.is_primary">
                                        <span class="prod-badge prod-badge-positive">{{ __('products.locations.primary') }}</span>
                                    </template>
                                </div>
                                <template x-if="loc.note">
                                    <p class="prod-loc-list-note" x-text="loc.note"></p>
                                </template>
                            </li>
                        </template>
                    </ul>
                </div>

                <div class="modal-foot">
                    <button type="button" class="pos-btn pos-btn-sm pos-btn-ghost" @click="locationsPanel = false">
                        {{ __('products.locations.details.close') }}
                    </button>
                </div>
            </div>
        </div>

        {{-- ══════════ Bulk shelf-location panel ══════════
             Two modes share one panel: `set` writes the coordinates below
             onto every product in scope; `clear` removes their pick face.
             Only the fields this company uses are shown (Settings → Stock
             locations), so a shop that tracks racks alone sees one box. --}}
        @can('products.update')
            <div class="scrim overlay-host" x-show="locationModal" x-cloak
                 @click.self="closeLocationModal()"
                 @keydown.escape.window="if (locationModal) closeLocationModal()">
                <div class="modal-card" role="dialog" aria-modal="true">
                    <div class="modal-head">
                        <div>
                            <div class="modal-title"
                                 x-text="locationMode === 'clear'
                                     ? @js(__('products.locations.bulk.clear_title'))
                                     : @js(__('products.locations.bulk.set_title'))"></div>
                            <div class="modal-sub"
                                 x-text="allFilteredSelected
                                     ? @js(__('products.locations.bulk.scope_filtered')).replace(':count', grandTotalLabel)
                                     : @js(__('products.locations.bulk.scope_selected')).replace(':count', selectedCount)"></div>
                        </div>
                        <button type="button" class="modal-x" @click="closeLocationModal()"
                                aria-label="{{ __('products.actions.cancel') }}">
                            <x-icon name="x" class="w-4 h-4" />
                        </button>
                    </div>

                    <div class="modal-body">
                        <template x-if="locationMode === 'set'">
                            <div>
                                <div class="form-stack">
                                    @foreach (($locationFields ?? []) as $f)
                                        @if ($f['enabled'])
                                            <label class="field">
                                                <span class="field-label">{{ $f['label'] }}</span>
                                                <input type="text"
                                                       class="pos-input"
                                                       maxlength="16"
                                                       autocomplete="off"
                                                       x-model="locationForm.{{ $f['key'] }}"
                                                       placeholder="{{ __('products.locations.placeholders.'.$f['key']) }}">
                                            </label>
                                        @endif
                                    @endforeach

                                    <label class="field">
                                        <span class="field-label">{{ __('products.locations.note') }}</span>
                                        <input type="text"
                                               class="pos-input"
                                               maxlength="64"
                                               autocomplete="off"
                                               x-model="locationForm.note"
                                               placeholder="{{ __('products.locations.note_placeholder') }}">
                                    </label>
                                </div>

                                {{-- The rule that makes this safe to run on 2,000
                                     products: nothing is erased. --}}
                                <x-alert type="info" class="mt-4">
                                    {{ __('products.locations.bulk.blank_hint') }}
                                </x-alert>
                            </div>
                        </template>

                        <template x-if="locationMode === 'clear'">
                            <x-alert type="warning">
                                {{ __('products.locations.bulk.clear_warning') }}
                            </x-alert>
                        </template>
                    </div>

                    <div class="modal-foot">
                        <button type="button" class="pos-btn pos-btn-sm pos-btn-ghost"
                                @click="closeLocationModal()">
                            {{ __('products.actions.cancel') }}
                        </button>
                        <button type="button"
                                class="pos-btn pos-btn-sm"
                                :class="locationMode === 'clear' ? 'pos-btn-danger' : 'pos-btn-primary'"
                                :disabled="locationSaving || (locationMode === 'set' && !locationFormHasValue)"
                                @click="submitLocation()">
                            <span x-text="locationSaving
                                ? @js(__('products.locations.bulk.applying'))
                                : (locationMode === 'clear'
                                    ? @js(__('products.locations.bulk.clear_confirm'))
                                    : @js(__('products.locations.bulk.set_confirm')))"></span>
                        </button>
                    </div>
                </div>
            </div>
        @endcan
    </div>
</x-admin-layout>
