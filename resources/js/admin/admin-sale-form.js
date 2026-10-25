import { posGet } from '../lib/http.js';
import { barcodeScanMixin } from './barcode-scan-mixin.js';

/**
 * Alpine factory for the back-office sale form (`admin/sales/create`).
 *
 * Structurally this is `purchaseForm`'s sibling — inline remote product
 * picker, `items[i][...]` inputs serialised by plain HTML form semantics,
 * a totals rail. One deliberate divergence: **totals are not computed
 * client-side.**
 *
 * The purchase editor can do its own arithmetic because a PO line carries
 * its own user-picked tax group and a flat exclusive rate. A sale can't:
 * the tax group comes from the product, and inclusive-vs-exclusive depends
 * on the group and the store. Reimplementing that here would be a second
 * source of truth that silently drifts from `PriceCart`. Instead every
 * change debounces into `/admin/sales/quote`, which runs the very same
 * `PriceCart` the save runs — so the preview is the answer, not a guess.
 *
 * @param {{
 *   searchUrl: string, quoteUrl: string,
 *   paymentMethods?: Array<{id:string,name:string,requires_reference:boolean}>,
 *   initialStoreId?: string|number, initialSaleDate?: string,
 *   initialCustomerId?: string|number, initialItems?: Array<object>,
 *   labels?: object,
 * }} config
 */
export function adminSaleForm({
    searchUrl,
    quoteUrl,
    scanUrl = null,
    paymentMethods = [],
    initialStoreId = '',
    initialSaleDate = '',
    initialCustomerId = '',
    initialItems = [],
    labels = {},
} = {}) {
    let _uid = 0;

    const lineFrom = (raw = {}) => ({
        _uid:             ++_uid,
        product_id:       raw.product_id ?? '',
        variant_id:       raw.variant_id ?? '',
        product_label:    raw.product_label ?? '',
        quantity:         raw.quantity ?? '1',
        discount_percent: raw.discount_percent ?? '0',
        notes:            raw.notes ?? '',
        // Filled in by the server quote — never typed, never trusted.
        unit_price:       null,
        line_total:       null,
    });

    const paymentFrom = (raw = {}) => ({
        _uid:              ++_uid,
        payment_method_id: raw.payment_method_id ?? (paymentMethods[0]?.id ?? ''),
        amount:            raw.amount ?? '',
        reference:         raw.reference ?? '',
    });

    return {
        ...barcodeScanMixin({ scanUrl }),
        // `$root` isn't reliable inside async callbacks, so the flash helper
        // keeps its own handle — same as the purchase editor.
        _el: null,

        labels,
        storeId:  initialStoreId ? String(initialStoreId) : '',
        customerId: initialCustomerId ? String(initialCustomerId) : '',
        saleDate: initialSaleDate || new Date(Date.now() - new Date().getTimezoneOffset() * 60000).toISOString().slice(0, 10),
        notes:    '',

        items:    initialItems.map(lineFrom),
        payments: [],
        currencyCode: '',

        totals: { subtotal: '0', discount_total: '0', tax_total: '0', grand_total: '0' },

        // Picker state
        query: '', results: [], searching: false, searchOpen: false,
        _searchTimer: null,
        _quoteTimer: null,
        _quoteSeq: 0,

        init() {
            this._el = this.$el;
            this.initBarcodeScan();
            // A store switch re-prices everything (per-store price lists).
            this.$watch('storeId', () => this.scheduleQuote());
            // …and so does a customer switch (their default discount).
            this.$watch('customerId', () => this.scheduleQuote());
            if (this.items.length > 0) this.scheduleQuote();
        },

        destroy() {
            this.destroyBarcodeScan();
        },

        /**
         * The one per-editor hook the scan mixin leaves to the host. Same
         * add-or-bump rule the picker uses, so scanning the same item twice
         * reads as "2 ×" rather than two identical lines.
         *
         * `row` is the resolver shape `{product_id, variant_id, label, sku}`
         * — note it carries `product_id` as an int, where the picker hands
         * back `value` as a string.
         */
        _onScanResolved(row) {
            const pid = String(row.product_id);
            const vid = row.variant_id ? String(row.variant_id) : '';

            const existing = this.items.find(
                (l) => String(l.product_id) === pid && String(l.variant_id || '') === vid,
            );

            if (existing) {
                existing.quantity = String((parseFloat(existing.quantity) || 0) + 1);
                this._flashLine(existing._uid);
            } else {
                this.items.push(lineFrom({
                    product_id:    pid,
                    variant_id:    vid,
                    product_label: row.label,
                }));
                this._flashLine(this.items[this.items.length - 1]._uid);
            }

            this.$store?.toasts?.push({ type: 'success', message: row.label, duration: 1500 });
            this.scheduleQuote();
        },

        /** Briefly highlight the touched row so a scan is visibly acknowledged. */
        _flashLine(uid) {
            this.$nextTick(() => {
                const el = this._el?.querySelector(`[data-line-uid="${uid}"]`);
                if (!el) return;
                el.classList.add('inv-scan-flash');
                setTimeout(() => el.classList.remove('inv-scan-flash'), 600);
            });
        },

        // ── Totals ─────────────────────────────────────────────────
        get paidTotal() {
            return this.payments.reduce((s, p) => s + (parseFloat(p.amount) || 0), 0);
        },
        get balanceDue() {
            const grand = parseFloat(this.totals.grand_total) || 0;
            return Math.max(0, grand - this.paidTotal);
        },

        formatMoney(amount) {
            return window.posFormatMoney
                ? window.posFormatMoney(amount, this.currencyCode || undefined)
                : Number(amount || 0).toFixed(2);
        },

        /**
         * Debounced re-quote. Every mutation routes through here rather than
         * recomputing locally, so the rail always shows server truth.
         */
        scheduleQuote() {
            clearTimeout(this._quoteTimer);
            this._quoteTimer = setTimeout(() => this._quote(), 300);
        },

        async _quote() {
            if (!this.storeId || this.items.length === 0) {
                this.totals = { subtotal: '0', discount_total: '0', tax_total: '0', grand_total: '0' };
                this.items.forEach((l) => { l.unit_price = null; l.line_total = null; });
                return;
            }

            // Responses can land out of order when the user types fast; only
            // the newest one is allowed to write the totals.
            const seq = ++this._quoteSeq;

            // The customer drives their automatic (group or per-customer)
            // discount, so the preview has to know who's buying or it
            // would quote list price and disagree with the saved sale.
            const params = { store_id: this.storeId, customer_id: this.customerId || '' };
            this.items.forEach((l, i) => {
                params[`items[${i}][product_id]`]       = l.product_id;
                params[`items[${i}][variant_id]`]       = l.variant_id || '';
                params[`items[${i}][quantity]`]         = l.quantity || '0';
                params[`items[${i}][discount_percent]`] = l.discount_percent || '0';
            });

            let data;
            try {
                ({ data } = await posGet(quoteUrl, params));
            } catch (e) {
                return; // interceptors surface the error; keep the last good total
            }
            if (seq !== this._quoteSeq || !data?.ok) return;

            this.currencyCode = data.currency_code || this.currencyCode;
            this.totals = data.totals ?? this.totals;
            (data.lines ?? []).forEach((row, i) => {
                if (!this.items[i]) return;
                this.items[i].unit_price = row.unit_price;
                this.items[i].line_total = row.line_total;
            });
        },

        // ── Product picker ─────────────────────────────────────────
        focusSearch()   { this.searchOpen = true; if (!this.results.length) this._fetch(); },
        onSearchInput() {
            this.searchOpen = true;
            clearTimeout(this._searchTimer);
            this._searchTimer = setTimeout(() => this._fetch(), 250);
        },
        // Delay so a click on a result lands before the list closes.
        closeSearch() { setTimeout(() => { this.searchOpen = false; }, 120); },

        async _fetch() {
            this.searching = true;
            try {
                const { data } = await posGet(searchUrl, this.query ? { q: this.query } : null);
                this.results = Array.isArray(data) ? data : [];
            } catch (e) {
                this.results = [];
            } finally {
                this.searching = false;
            }
        },

        addProduct(row, variant = null) {
            const pid = String(row.value);
            const vid = variant ? String(variant.id) : '';

            // Same (product, variant) already on the cart → bump instead of
            // stacking a duplicate line, matching the cashier and the PO editor.
            const existing = this.items.find(
                (l) => String(l.product_id) === pid && String(l.variant_id || '') === vid,
            );
            if (existing) {
                existing.quantity = String((parseFloat(existing.quantity) || 0) + 1);
            } else {
                this.items.push(lineFrom({
                    product_id:    pid,
                    variant_id:    vid,
                    product_label: variant ? `${row.label} — ${variant.label}` : row.label,
                }));
            }

            this.query = '';
            this.results = [];
            this.searchOpen = false;
            this.scheduleQuote();
        },

        removeLine(uid) {
            this.items = this.items.filter((l) => l._uid !== uid);
            this.scheduleQuote();
        },

        // ── Payments ───────────────────────────────────────────────
        addPayment() {
            // Default the first row to whatever is still owed, so the common
            // "pay it all now" case is one click.
            const remaining = this.balanceDue;
            this.payments.push(paymentFrom({
                amount: remaining > 0 ? remaining.toFixed(2) : '',
            }));
        },
        removePayment(uid) {
            this.payments = this.payments.filter((p) => p._uid !== uid);
        },
    };
}
