import { posPost } from '../lib/http.js';

/**
 * Bulk image URLs — the second tab of /admin/products/bulk-images.
 *
 * Sibling of `bulkImageMatcher`, not a replacement for it. That one uploads
 * files the customer already has; this one hands the server links and lets it
 * download them. A customer can use either, or both, and the two never touch
 * each other's state — they're separate Alpine components in separate panels.
 *
 * Two sources of links:
 *   - **pending** — what a CSV import already declared. The server picks the
 *     rows itself; we send no ids.
 *   - **paste** — `SKU,URL` lines out of a spreadsheet.
 *
 * The run is a loop of small requests (five URLs each) rather than one long
 * one, for the same reason the upload path batches: a shared host gives us a
 * ~30-second ceiling per request, and a page of dead links would blow straight
 * through it. Two things fall out of that:
 *
 *   - The server may hand rows back as `deferred` when it runs out of its own
 *     time budget. Those aren't failures — they go to the front of the queue
 *     and ride along in the next request.
 *   - If the very first batch comes back entirely unreachable, the host almost
 *     certainly blocks outbound HTTP. We STOP there and say so, because the
 *     alternative is ten seconds of silence per link for the next three
 *     thousand links. The customer can override with "continue anyway".
 *
 * Shared by the product screen and the brand-logos screen. The only difference
 * between them is which field names a row — `sku` for a product, `name` for a
 * brand — so that's the one thing `refKey` configures. Everything else, right
 * down to the error codes, is identical, which is why they share this rather
 * than forking it (same reasoning as `bulkImageMatcher`).
 *
 * @param {{
 *   matchUrl: string, fetchUrl: string, batchSize: number,
 *   pendingCount: number, refKey: string, labels: Record<string,string>,
 * }} config
 */
export function bulkImageUrls(config = {}) {
    return {
        matchUrl:     config.matchUrl,
        fetchUrl:     config.fetchUrl,
        batchSize:    config.batchSize ?? 5,
        pendingCount: config.pendingCount ?? 0,
        // 'sku' | 'name' — the field the server identifies a row by.
        refKey:       config.refKey ?? 'sku',
        labels:       config.labels ?? {},

        // 'idle' → 'matching' → 'ready' → 'fetching' → 'done'
        phase: 'idle',
        // 'pending' (declared by an import) | 'paste' (SKU,URL lines)
        source: 'pending',
        pasteText: '',

        // Rows carry a `status`: 'ready' | 'fetching' | 'done' | 'error'.
        matched:   [],
        unmatched: [],

        fetchedCount: 0,
        fetchTotal:   0,

        // Set when a whole first batch failed to connect. Sticky until the
        // customer either starts over or overrides it.
        blocked: false,
        stopped: false,

        // ── Match ──────────────────────────────────────────────────
        loadPending() {
            this.source = 'pending';
            this._match({ source: 'pending' });
        },

        matchPaste() {
            this.source = 'paste';
            if (this.pasteText.trim() === '') {
                this.$store.toasts?.push({ type: 'info', message: this.labels.paste_empty });
                return;
            }
            this._match({ source: 'paste', text: this.pasteText });
        },

        async _match(payload) {
            this.phase = 'matching';
            this.matched = [];
            this.unmatched = [];
            this.blocked = false;
            this.stopped = false;

            let data;
            try {
                ({ data } = await posPost(this.matchUrl, payload));
            } catch (e) {
                this.phase = 'idle';
                this.$store.toasts?.push({ type: 'error', message: e?.message || this.labels.error_generic });
                return;
            }

            this.matched = (data.matched ?? []).map((m) => ({
                ...m, status: 'ready', error: null, image_url: null,
            }));
            this.unmatched = data.unmatched ?? [];
            this.phase = 'ready';
        },

        // ── Fetch ──────────────────────────────────────────────────

        /** @param {boolean} force skip the "host blocks outbound" stop */
        async fetchAll(force = false) {
            const queue = this.matched.filter((r) => r.status === 'ready' || r.status === 'error');
            if (queue.length === 0) return;

            queue.forEach((r) => { r.status = 'ready'; r.error = null; });

            this.phase = 'fetching';
            this.fetchTotal   = queue.length;
            this.fetchedCount = 0;
            this.blocked = false;
            this.stopped = false;

            let firstBatch = true;
            // The server always attempts at least one row per call, so the
            // queue is guaranteed to shrink. The counter is a backstop against
            // a server that somehow doesn't, not an expected path.
            let guard = queue.length * 3 + 20;

            while (queue.length > 0 && !this.stopped && guard-- > 0) {
                const batch    = queue.splice(0, this.batchSize);
                const deferred = await this._fetchBatch(batch, firstBatch && !force);
                if (deferred.length > 0) queue.unshift(...deferred);
                firstBatch = false;
            }

            this.phase = 'done';

            if (!this.blocked && this.matched.some((r) => r.status === 'error')) {
                this.$store.toasts?.push({ type: 'warning', message: this.labels.error_generic });
            }
        },

        /**
         * One request. Returns the rows the server ran out of time for, which
         * the caller puts back at the front of the queue.
         */
        async _fetchBatch(batch, watchForBlocked) {
            batch.forEach((r) => { r.status = 'fetching'; });

            let data;
            try {
                ({ data } = await posPost(this.fetchUrl, {
                    rows: batch.map((r) => ({ [this.refKey]: r[this.refKey], url: r.url })),
                }));
            } catch (e) {
                // Whole-batch failure (5xx, network, the tab going offline).
                // Mark the rows and carry on — a later batch may still work.
                batch.forEach((r) => { r.status = 'error'; r.error = e?.message || this.labels.error_generic; });
                this.fetchedCount += batch.length;
                return [];
            }

            const byRef = {};
            for (const res of data.results ?? []) byRef[res[this.refKey]] = res;

            const deferred = [];
            for (const row of batch) {
                const res = byRef[row[this.refKey]];

                if (res?.ok) {
                    row.status = 'done';
                    row.has_image = true;
                    if (res.image_url) row.image_url = res.image_url;
                    this.fetchedCount += 1;
                } else if (res?.error === 'deferred') {
                    // Not a failure — the server's time budget, not this URL.
                    row.status = 'ready';
                    deferred.push(row);
                } else {
                    row.status = 'error';
                    row.error = res?.error || 'error';
                    this.fetchedCount += 1;
                }
            }

            if (watchForBlocked && data.all_unreachable) {
                this.blocked = true;
                this.stopped = true;
                // Roll this batch back to untried. Nothing about these URLs is
                // known to be wrong — the connection never happened — so they
                // shouldn't be shown as failures, and "continue anyway" starts
                // from a clean queue.
                batch.forEach((r) => { r.status = 'ready'; r.error = null; });
                this.fetchedCount = 0;
                return [];
            }

            return deferred;
        },

        /** "Continue anyway" after the blocked warning. */
        continueAnyway() {
            this.blocked = false;
            this.fetchAll(true);
        },

        // ── Failure export ─────────────────────────────────────────
        downloadFailed() {
            const rows = this.matched.filter((r) => r.status === 'error');
            if (rows.length === 0) return;
            this._downloadCsv(
                'failed-image-urls.csv',
                [
                    `${this.refKey},url,reason`,
                    ...rows.map((r) => `"${r[this.refKey]}","${r.url}",${r.error}`),
                ],
            );
        },

        downloadUnmatched() {
            if (this.unmatched.length === 0) return;
            this._downloadCsv(
                'unmatched-image-urls.csv',
                ['line,reason', ...this.unmatched.map((u) => `"${u.line}",${u.reason}`)],
            );
        },

        _downloadCsv(filename, lines) {
            const blob = new Blob([lines.join('\n')], { type: 'text/csv' });
            const url  = URL.createObjectURL(blob);
            const a    = document.createElement('a');
            a.href = url;
            a.download = filename;
            document.body.appendChild(a);
            a.click();
            a.remove();
            URL.revokeObjectURL(url);
        },

        reset() {
            this.phase = 'idle';
            this.matched = [];
            this.unmatched = [];
            this.pasteText = '';
            this.fetchedCount = 0;
            this.fetchTotal = 0;
            this.blocked = false;
            this.stopped = false;
        },

        // ── Display helpers ────────────────────────────────────────
        get summaryText() {
            return this._t('summary', { matched: this.matched.length, unmatched: this.unmatched.length });
        },
        get matchedTitle()   { return this._t('matched_title',   { count: this.matched.length }); },
        get unmatchedTitle() { return this._t('unmatched_title', { count: this.unmatched.length }); },
        get fetchButtonText() {
            const n = this.matched.filter((r) => r.status !== 'done').length || this.matched.length;
            return this._t('fetch_button', { count: n });
        },
        get fetchingText() { return this._t('fetching', { done: this.fetchedCount, total: this.fetchTotal }); },
        get doneText() {
            return this._t('done_summary', { fetched: this.matched.filter((r) => r.status === 'done').length });
        },

        /** Translate a server error code; unknown codes fall back to generic. */
        errorLabel(code) {
            return this.labels['err_' + code] ?? this.labels.err_generic ?? code;
        },

        reasonLabel(reason) {
            return this.labels['reason_' + reason] ?? reason;
        },

        _t(key, params) {
            let s = this.labels[key] ?? '';
            for (const [k, v] of Object.entries(params)) {
                s = s.replaceAll(':' + k, String(v));
            }
            return s;
        },
    };
}
