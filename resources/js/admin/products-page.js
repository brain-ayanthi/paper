import { composeDataTableServer } from './data-table-server.js';
import { posPost } from '../lib/http.js';

/**
 * Alpine factory for the Products index page.
 *
 * Server-paginated (see `data-table-server.js`). The server renders only the
 * first page of the active view; search, filters, sort, paging and the grid's
 * infinite scroll each fetch one page of rows from `/admin/products/rows`.
 *
 * The catalog can run to tens of thousands of SKUs. The previous
 * render-everything-then-hide-rows approach put ~17 Alpine directives per
 * product into the DOM — twice over, since table rows and grid cards were both
 * rendered — and a 7k-SKU catalog timed the page out. Only the active view's
 * rows exist in the DOM now; switching view re-fetches.
 *
 * @param {{
 *   endpoint: string, view: 'table'|'grid', perPage: number, gridPageSize: number,
 *   total: number, page: number, totalPages: number, grandTotal: number,
 *   summary: {total: number, active: number, featured: number},
 * }} config
 */
export function productsPage(config = {}) {
    return composeDataTableServer(
        {
            endpoint:        config.endpoint,
            initialPageSize: config.perPage ?? 25,
        },
        {
            // ── Filter state ───────────────────────────────────────
            categoryFilter: 'all',   // 'all' | category id (as string)
            typeFilter:     'all',   // 'all' | 'simple' | 'variant' | 'kit'
            activeFilter:   'all',   // 'all' | 'active' | 'inactive'
            featuredFilter: 'all',   // 'all' | 'featured'
            // Shelf location at the ACTIVE store: 'all' | 'yes' | 'no'.
            // 'no' answers "what still has nowhere to stand?", which is how
            // a shop works through its catalog after turning locations on.
            locatedFilter:  'all',

            // ── View toggle ────────────────────────────────────────
            view: config.view ?? 'grid',

            // The table's page size is user-adjustable via the pager; the grid's
            // is the infinite-scroll batch and fixed. Switching view swaps which
            // is in effect — so these must NOT default to `config.perPage`, which
            // is only whichever view the server rendered.
            tablePageSize: config.tablePageSize ?? 25,
            gridPageSize:  config.gridPageSize ?? 30,

            // Unfiltered catalog size — the "N of GRAND" toolbar counter.
            grandTotal: config.grandTotal ?? 0,

            // ── Bulk actions ────────────────────────────────────────
            // Endpoints are absent when the corresponding permission is absent.
            bulkLocationUrl: config.bulkLocationUrl ?? '',
            bulkDeleteUrl:   config.bulkDeleteUrl ?? '',
            bulkDeleteText:  config.bulkDeleteText ?? {},
            bulkDeleting:    false,

            /** Product ids ticked on the CURRENT page. Selection is
             *  deliberately dropped whenever the result set changes (see
             *  _dtAfterSwap): carrying ticks across pages you can no longer
             *  see is how people bulk-edit the wrong products. Acting on
             *  more than one page is what `allFilteredSelected` is for. */
            selectedIds: [],

            /** True once the user opts into "every product matching these
             *  filters" — the server then re-derives the set from the filter
             *  params rather than from any id list. */
            allFilteredSelected: false,

            /** Ids rendered on the current page, refreshed on every swap. */
            pageIds: [],

            locationModal:  false,
            locationMode:   'set',       // 'set' | 'clear'
            locationSaving: false,
            locationForm:   { aisle: '', rack: '', shelf: '', bin: '', note: '' },

            // ── Location details (read-only) ───────────────────────
            // A product can occupy several places at one store; a table
            // cell or grid card can show one, so both hand the full list
            // to this panel rather than truncating it.
            locationsPanel:   false,
            locationsProduct: '',
            locationsRows:    [],

            summary: { total: 0, active: 0, featured: 0 },

            // ── Row-level quick actions ────────────────────────────
            // Product ids whose toggle is mid-flight, so a fast double-click
            // can't race the AJAX.
            togglingIds: [],

            /**
             * Reactive per-row state, keyed by product id: `{ is_active,
             * is_featured }`.
             *
             * The `data-dt-active` / `data-dt-featured` DOM attributes aren't
             * reactive — Alpine doesn't observe `element.dataset` mutations, so
             * bindings that read them never re-render. Mutating this object
             * does. Rebuilt from the server's markup after every row swap.
             */
            rowState: {},

            // The pager belongs to the table view; the grid pages by scrolling.
            get isTable() { return this.view === 'table'; },

            get hasActiveFilters() {
                return this.categoryFilter !== 'all'
                    || this.typeFilter     !== 'all'
                    || this.activeFilter   !== 'all'
                    || this.featuredFilter !== 'all'
                    || this.locatedFilter  !== 'all'
                    || (this.search ?? '').trim() !== '';
            },

            resetFilters() {
                this.categoryFilter = 'all';
                this.typeFilter     = 'all';
                this.activeFilter   = 'all';
                this.featuredFilter = 'all';
                this.locatedFilter  = 'all';
                this.search         = '';
            },

            init() {
                this.initDataTableServer({
                    total:      config.total ?? 0,
                    page:       config.page ?? 1,
                    totalPages: config.totalPages ?? 1,
                    perPage:    config.perPage ?? 25,
                });

                this.summary = config.summary ?? this.summary;

                // Seed rowState from the first page, which the server rendered
                // inline — no fetch needed on boot.
                this._syncRowState(this._dtContainer());

                // Every filter change restarts at page 1. `_dtLoad` collapses
                // the burst these fire during `resetFilters()` into one request.
                ['categoryFilter', 'typeFilter', 'activeFilter', 'featuredFilter', 'locatedFilter']
                    .forEach((prop) => this.$watch(prop, () => this._dtGo(1)));

                this.$nextTick(() => this._initGridInfiniteScroll());
            },

            // ── data-table-server host contract ────────────────────
            _dtContainer() {
                const sel = this.view === 'table' ? '[data-dt-rows="table"]' : '[data-dt-rows="grid"]';
                return this._dtRoot?.querySelector(sel) ?? null;
            },

            _dtExtraParams() {
                return {
                    view:     this.view,
                    category: this.categoryFilter,
                    type:     this.typeFilter,
                    status:   this.activeFilter,
                    featured: this.featuredFilter,
                    located:  this.locatedFilter,
                };
            },

            _dtAfterSwap(container) {
                this._syncRowState(container);

                // A new result set invalidates any tick the user made —
                // acting on rows they can no longer see is how people
                // bulk-edit the wrong products.
                this.clearSelection();

                // A replaced grid is a fresh result set — let the sentinel
                // re-trigger in case the new first page doesn't fill the screen.
                if (this.view === 'grid') this._reobserveSentinel();
            },

            _dtOnMeta(meta) {
                if (meta.summary) this._writeSummary(meta.summary);
            },

            /** The grid stacks pages by appending; the table replaces them. */
            _dtAppends() { return this.view === 'grid'; },

            // ── View toggle ────────────────────────────────────────
            setView(view) {
                if (view === this.view) return;
                this.view     = view;
                this.pageSize = view === 'table' ? this.tablePageSize : this.gridPageSize;
                this._dtGo(1);
            },

            /** Pager page-size picker — table view only. Remembered so toggling
             *  to grid and back doesn't reset the choice. */
            setPageSize(n) {
                this.pageSizeOpen = false;
                if (n === this.pageSize) return;
                this.tablePageSize = n;
                this.pageSize      = n;
                this._dtGo(1);
            },

            // ── Grid infinite scroll ───────────────────────────────
            /** Grow the grid by one server page when the sentinel nears view. */
            async loadMore() {
                if (this.view !== 'grid') return;
                if (this._dtLoading || this.page >= this.totalPages) return;

                const target = this.page + 1;
                this.page = target;

                const loaded = await this._dtLoad({ append: true });

                // A failed append would otherwise strand `page` one ahead, so the
                // next scroll would fetch target+1 and silently skip a page of the
                // catalog. Only rewind if nothing has navigated since.
                if (!loaded && this.page === target) this.page = target - 1;
            },

            _initGridInfiniteScroll() {
                const sentinel = this.$refs?.gridSentinel;
                if (!sentinel || !('IntersectionObserver' in window)) return;

                this._gridObserver = new IntersectionObserver(
                    (entries) => { if (entries.some((e) => e.isIntersecting)) this.loadMore(); },
                    { rootMargin: '600px 0px' },
                );
                this._gridObserver.observe(sentinel);
            },

            /**
             * The observer only fires on intersection *changes*. When an
             * appended batch is short (say three rows of cards) the sentinel can
             * stay in view and never re-trigger. Re-observing forces a fresh
             * check next frame, so we keep filling until the sentinel is pushed
             * past the viewport or every page is loaded.
             */
            _reobserveSentinel() {
                const sentinel = this.$refs?.gridSentinel;
                if (!this._gridObserver || !sentinel) return;
                this._gridObserver.unobserve(sentinel);
                this.$nextTick(() => {
                    if (this.$refs?.gridSentinel) this._gridObserver.observe(this.$refs.gridSentinel);
                });
            },

            // ── Summary cards ──────────────────────────────────────
            /**
             * The cards describe the *filtered* set, so they arrive from the
             * server with every rows() response rather than being counted from
             * the visible rows (which are now only one page).
             */
            _writeSummary(summary) {
                this.summary = summary;
                const root = this._dtRoot;
                if (!root) return;

                Object.entries(summary).forEach(([key, value]) => {
                    root.querySelectorAll(`[data-card-value="${key}"]`)
                        .forEach((el) => { el.textContent = Number(value).toLocaleString(); });
                });
            },

            /** Keep the cards honest after an optimistic toggle, without paying
             *  for a round trip just to recount. */
            _bumpSummary(field, next) {
                const key = field === 'is_active' ? 'active' : 'featured';
                this._writeSummary({ ...this.summary, [key]: this.summary[key] + (next ? 1 : -1) });
            },

            // ── Row state ──────────────────────────────────────────
            /** Rebuild `rowState` from the server's `data-dt-*` attributes.
             *  Merged, not replaced, so appended grid pages keep earlier rows. */
            _syncRowState(container) {
                if (!container) return;
                const fresh = {};
                const ids   = [];
                container.querySelectorAll('[data-dt-product-id]').forEach((row) => {
                    const id = parseInt(row.dataset.dtProductId, 10);
                    if (!Number.isFinite(id)) return;
                    ids.push(id);
                    fresh[id] = {
                        is_active:   row.dataset.dtActive   === '1',
                        is_featured: row.dataset.dtFeatured === '1',
                    };
                });
                Object.assign(this.rowState, fresh);
                this.pageIds = ids;
            },

            // ── Bulk selection ─────────────────────────────────────
            get selectedCount() {
                return this.allFilteredSelected ? this.total : this.selectedIds.length;
            },

            /** The filtered total, formatted — what both the bar and the
             *  panel quote back so the user knows the blast radius. */
            get grandTotalLabel() {
                return Number(this.total || 0).toLocaleString();
            },

            get pageFullySelected() {
                return this.pageIds.length > 0
                    && this.pageIds.every((id) => this.selectedIds.includes(id));
            },

            /** Offer "select all N" only when the page is fully ticked and
             *  there is genuinely more beyond it. */
            get canSelectAllFiltered() {
                return this.pageFullySelected
                    && !this.allFilteredSelected
                    && this.total > this.pageIds.length;
            },

            isSelected(id) {
                return this.allFilteredSelected || this.selectedIds.includes(id);
            },

            toggleSelection(id, on) {
                // Un-ticking any row drops out of "all filtered" mode — the
                // set is no longer "everything that matches".
                if (this.allFilteredSelected) {
                    this.allFilteredSelected = false;
                    this.selectedIds = [...this.pageIds];
                }

                if (on) {
                    if (!this.selectedIds.includes(id)) this.selectedIds.push(id);
                } else {
                    this.selectedIds = this.selectedIds.filter((x) => x !== id);
                }
            },

            togglePageSelection(on) {
                this.allFilteredSelected = false;
                this.selectedIds = on
                    ? [...new Set([...this.selectedIds, ...this.pageIds])]
                    : this.selectedIds.filter((id) => !this.pageIds.includes(id));
            },

            selectAllFiltered() {
                this.allFilteredSelected = true;
            },

            clearSelection() {
                this.selectedIds = [];
                this.allFilteredSelected = false;
            },

            confirmBulkDelete() {
                // "All matching" may span the whole catalog. Deletion is
                // intentionally limited to the explicit ids checked on-page.
                if (this.allFilteredSelected || !this.bulkDeleteUrl || this.bulkDeleting || this.selectedIds.length < 1) return;

                const count = Number(this.selectedIds.length).toLocaleString();
                this.$store.confirm.show({
                    title:        String(this.bulkDeleteText.title ?? '').replace(':count', count),
                    message:      this.bulkDeleteText.warning,
                    intent:       'danger',
                    confirmLabel: this.bulkDeleteText.confirm,
                    cancelLabel:  this.bulkDeleteText.cancel,
                    onConfirm:    () => this.submitBulkDelete(),
                });
            },

            async submitBulkDelete() {
                if (this.bulkDeleting || !this.bulkDeleteUrl || this.selectedIds.length < 1) return;

                this.bulkDeleting = true;
                try {
                    const { data } = await posPost(this.bulkDeleteUrl, {
                        ids: this.selectedIds,
                    });

                    this.clearSelection();
                    this.dtInvalidate();
                    await this._dtLoad({ bustCache: true });

                    if (data?.message) {
                        this.$store.toasts?.push({
                            type:    data.blocked > 0 ? 'warning' : 'success',
                            message: data.message,
                        });
                    }
                } finally {
                    this.bulkDeleting = false;
                }
            },

            /** Open the read-only details panel for one product's locations.
             *  Rows are handed in from the server-rendered row/card, so no
             *  fetch and nothing to keep in sync after a page swap. */
            openLocations(productName, rows) {
                this.locationsProduct = productName ?? '';
                this.locationsRows    = Array.isArray(rows) ? rows : [];
                this.locationsPanel   = true;
            },

            // ── Bulk shelf location ────────────────────────────────
            get locationFormHasValue() {
                return ['aisle', 'rack', 'shelf', 'bin', 'note']
                    .some((k) => String(this.locationForm[k] ?? '').trim() !== '');
            },

            openLocationModal(mode) {
                this.locationMode  = mode === 'clear' ? 'clear' : 'set';
                this.locationForm  = { aisle: '', rack: '', shelf: '', bin: '', note: '' };
                this.locationModal = true;
            },

            closeLocationModal() {
                if (this.locationSaving) return;
                this.locationModal = false;
            },

            async submitLocation() {
                if (this.locationSaving || !this.bulkLocationUrl) return;
                if (this.locationMode === 'set' && !this.locationFormHasValue) return;

                this.locationSaving = true;
                try {
                    // The filter params ride along so the server can rebuild
                    // the same set the user is looking at for `filtered`
                    // scope — it never trusts a client-side id list for that.
                    const { data } = await posPost(this.bulkLocationUrl, {
                        mode:  this.locationMode,
                        scope: this.allFilteredSelected ? 'filtered' : 'selected',
                        ids:   this.allFilteredSelected ? [] : this.selectedIds,
                        ...this.locationForm,
                        ...this._dtExtraParams(),
                        q: this.search ?? '',
                    });

                    this.locationModal = false;
                    this.clearSelection();

                    // The Location column is now stale on every visible row.
                    this.dtInvalidate();
                    await this._dtLoad({ bustCache: true });

                    if (data?.message) {
                        this.$store.toasts?.push({
                            type:    data.capped ? 'warning' : 'success',
                            message: data.message,
                        });
                    }
                } finally {
                    this.locationSaving = false;
                }
            },

            // ── Margin (client-side, from data-dt-price / data-dt-cost) ──
            marginPct(row) {
                const price = parseFloat(row.dataset.dtPrice || '0');
                const cost  = parseFloat(row.dataset.dtCost  || '0');
                if (!price) return 0;
                return Math.round(((price - cost) / price) * 100);
            },

            /** Matches the mockup's positive (≥35) / danger (<20) thresholds. */
            marginClass(row) {
                const m = this.marginPct(row);
                if (m < 20)  return 'prod-margin-danger';
                if (m >= 35) return 'prod-margin-positive';
                return '';
            },

            // ── Quick toggles ──────────────────────────────────────
            /**
             * Flip one boolean flag without opening the editor. Optimistic: the
             * reactive `rowState` write updates the badge, the button's pressed
             * state and the summary card immediately; a server failure reverts.
             *
             * `field` must be 'is_active' or 'is_featured' — the server enforces
             * the whitelist too.
             */
            async toggleField(id, field) {
                if (this.togglingIds.includes(id)) return;
                if (!this.rowState[id]) return;

                const current = !!this.rowState[id][field];
                const next    = !current;

                this.rowState[id][field] = next;
                this._bumpSummary(field, next);
                this.togglingIds = [...this.togglingIds, id];

                let data;
                try {
                    ({ data } = await posPost(`/admin/products/${id}/toggle`, {
                        field,
                        value: next ? '1' : '0',
                    }));
                } catch (e) {
                    this.rowState[id][field] = current;
                    this._bumpSummary(field, current);
                    this.togglingIds = this.togglingIds.filter((x) => x !== id);
                    this.$store.toasts?.push({
                        type:    'error',
                        message: e?.status === undefined ? 'Network error.' : (e?.message || 'Could not update product.'),
                    });
                    return;
                }

                this.togglingIds = this.togglingIds.filter((x) => x !== id);

                // Cached pages now describe stale flags.
                this.dtInvalidate();

                // With a status/featured filter active the row may no longer
                // belong on this page — reload so it leaves, and so the summary
                // comes from the server's count rather than our optimistic bump.
                if (this.activeFilter !== 'all' || this.featuredFilter !== 'all') {
                    this._dtLoad({ bustCache: true });
                }

                if (data?.message) {
                    this.$store.toasts?.push({ type: 'success', message: data.message });
                }
            },
        },
    );
}
