/**
 * Customer-Facing Display (CFD) — standalone entry (Slice 1).
 *
 * A lean, presentation-only page: it holds no cart, does no pricing, and
 * resolves UPI intents on the server. It subscribes to the cashier's same-machine
 * BroadcastChannel (or polls the relay) and paints the latest snapshot. Bundled
 * separately from admin.js so the second screen doesn't pull the whole
 * back-office. See docs/features/customer-display.md.
 */
import Alpine from 'alpinejs';
import QRCode from 'qrcode';
import { subscribeCfd } from './cfd-broadcast.js';

export function customerDisplay(bootstrap = {}) {
    return {
        // ── Bootstrap (server-rendered) ──────────────────────────
        channel:  bootstrap.channel || 'pos-cfd:default',
        // Transport (Slice 4): 'same_machine' subscribes to the local
        // BroadcastChannel; 'separate_device' polls the relay endpoint.
        transport: bootstrap.transport || 'same_machine',
        pollUrl:   bootstrap.poll_url || null,
        welcome:  bootstrap.welcome || '',
        tagline:  bootstrap.tagline || '',
        thankyou: bootstrap.thankyou || '',
        labels:   bootstrap.labels || {},
        // When on, mirror the cashier's UPI payment QR onto the payment
        // screen (per-terminal opt-in). Off → UPI stays on the till only.
        showUpiQr: !!bootstrap.show_upi_qr,
        upiUrl: bootstrap.upi_url || null,

        // Attract-media slideshow (Slice 3).
        attract:  bootstrap.attract || { media: [], seconds: 8 },
        promoIx:  0,

        // ── Live state ───────────────────────────────────────────
        state:    'idle',   // 'idle' | 'sale' | 'payment' | 'thankyou'
        snap:     { last_line: null, lines: [], totals: {}, customer: null, payment: null, thankyou: null },
        clock:    '',
        payQr:    '',       // data-URL of the pay_url QR (payment state)
        upiQr:    '',       // data-URL of the upi_pay_url QR (payment state)
        receiptQr: '',      // data-URL of the receipt_url QR (thank-you state)
        _payQrUrl: null,    // last URLs we rendered, to skip redundant regens
        _upiQrUrl: null,
        _upiIntent: null,
        _receiptQrUrl: null,
        _lastSeq: -1,
        _unsub:   null,

        /** Newest line first — the just-scanned item sits at the top and
         *  gets the flash highlight. */
        get linesNewestFirst() {
            return [...(this.snap.lines || [])].reverse();
        },

        get itemCountLabel() {
            const n = Number(this.snap?.totals?.item_count ?? 0);
            return `${this.snap?.totals?.item_count ?? 0} ${n === 1 ? this.labels.item : this.labels.items}`;
        },

        get hasAttract() {
            return Array.isArray(this.attract.media) && this.attract.media.length > 0;
        },

        init() {
            this._tick();
            this._clockTimer = setInterval(() => this._tick(), 20000);

            // Pick the transport. A separate device can't hear the local
            // BroadcastChannel, so it polls the relay instead.
            if (this.transport === 'separate_device' && this.pollUrl) {
                this._startPolling();
            } else {
                this._unsub = subscribeCfd(this.channel, (s) => this._apply(s));
            }

            // Rotate the attract slideshow (only when there's more than one).
            if (this.hasAttract && this.attract.media.length > 1) {
                const ms = Math.max(3, Number(this.attract.seconds) || 8) * 1000;
                this._attractTimer = setInterval(() => {
                    this.promoIx = (this.promoIx + 1) % this.attract.media.length;
                }, ms);
            }
        },

        /** Teardown (code-review #30) — a standalone page unloads anyway,
         *  but holding the handles keeps this leak-free if ever embedded. */
        destroy() {
            clearInterval(this._clockTimer);
            clearInterval(this._attractTimer);
            clearTimeout(this._pollTimer);
            if (this._onVisibility) document.removeEventListener('visibilitychange', this._onVisibility);
            this._unsub?.();
        },

        /** Apply an incoming snapshot, dropping stale / out-of-order frames. */
        _apply(s) {
            if (!s || typeof s !== 'object') return;
            if (typeof s.seq === 'number' && s.seq <= this._lastSeq) return;
            this._lastSeq = typeof s.seq === 'number' ? s.seq : this._lastSeq;
            this.snap  = s;
            this.state = ['sale', 'payment', 'thankyou'].includes(s.state) ? s.state : 'idle';
            this._maybeQr();
        },

        /** (Re)render the pay-URL and receipt-URL QRs when they change —
         *  async, so kept off the snapshot itself. The cashier sends the
         *  URL; we draw the code. */
        async _maybeQr() {
            const payUrl = this.snap?.payment?.pay_url || null;
            if (payUrl !== this._payQrUrl) {
                this._payQrUrl = payUrl;
                this.payQr = payUrl ? await this._toQr(payUrl) : '';
            }

            // UPI QR — only when this terminal opted in; otherwise the raw
            // deep link is ignored and never drawn.
            const upiUrl = this.showUpiQr ? (this.snap?.payment?.upi_pay_url || null) : null;
            if (this.transport !== 'separate_device') {
                this._resolveUpiIntent();
            } else if (upiUrl !== this._upiQrUrl) {
                this._upiQrUrl = upiUrl;
                this.upiQr = upiUrl ? await this._toQr(upiUrl) : '';
            }

            const receiptUrl = this.snap?.thankyou?.receipt_url || null;
            if (receiptUrl !== this._receiptQrUrl) {
                this._receiptQrUrl = receiptUrl;
                this.receiptQr = receiptUrl ? await this._toQr(receiptUrl, 200) : '';
            }
        },

        /** Local broadcasts carry an intent, never an authoritative payment URL. */
        async _resolveUpiIntent() {
            const intent = this.showUpiQr && this.state === 'payment'
                ? (this.snap?.payment?.upi_intent || null) : null;
            if (intent === this._upiIntent) return;
            this._upiIntent = intent;
            this.upiQr = '';
            if (!intent || !this.upiUrl) return;

            try {
                const url = new URL(this.upiUrl, window.location.origin);
                url.searchParams.set('intent', intent);
                const response = await fetch(url, {
                    headers: { Accept: 'application/json' },
                    credentials: 'same-origin',
                    cache: 'no-store',
                });
                if (!response.ok) throw new Error('UPI intent lookup failed');
                const data = await response.json();
                const qr = data.upi_pay_url ? await this._toQr(data.upi_pay_url) : '';
                if (this._upiIntent === intent) this.upiQr = qr;
            } catch (_) {
                // Retry on the next snapshot, but never revive a superseded QR.
                if (this._upiIntent === intent) this._upiIntent = null;
            }
        },

        async _toQr(url, width = 320) {
            try {
                return await QRCode.toDataURL(url, { width, margin: 1, errorCorrectionLevel: 'M' });
            } catch (e) {
                return '';
            }
        },

        _tick() {
            const d = new Date();
            this.clock = d.toLocaleTimeString(undefined, { hour: '2-digit', minute: '2-digit' });
        },

        /** Flip light/dark on the display and remember it in the display's own
         *  key (`pos_cfd_theme`) — kept separate from the cashier's theme. The
         *  moon/sun icon swap is CSS-driven off the `.dark` class. */
        toggleTheme() {
            const isDark = document.documentElement.classList.toggle('dark');
            try { localStorage.setItem('pos_cfd_theme', isDark ? 'dark' : 'light'); } catch (e) { /* private mode */ }
        },

        /** Separate-device transport: poll the relay for the latest frame.
         *  Only applies frames that carry a seq (an empty relay is ignored,
         *  so the display holds its last state). Errors are swallowed — a
         *  dropped poll just retries on the next tick.
         *
         *  Adaptive cadence (code-review #27): 1.5s only while a sale is in
         *  flight — that's when the shopper is watching lines appear. After
         *  30s with no new frame on an idle screen it backs off to 5s, and
         *  polling pauses entirely while the tab is hidden (resuming with an
         *  immediate poll on visibility). Cuts the constant per-display
         *  server load without touching in-sale responsiveness. */
        _startPolling() {
            const ACTIVE_MS  = 1500;   // in-sale cadence (unchanged)
            const IDLE_MS    = 5000;   // idle-screen cadence
            const IDLE_AFTER = 30000;  // quiet time before backing off
            let lastFrameAt  = Date.now();

            const poll = async () => {
                try {
                    const res = await fetch(this.pollUrl, {
                        headers: { 'Accept': 'application/json' },
                        credentials: 'same-origin',
                        cache: 'no-store',
                    });
                    if (!res.ok) return;
                    const data = await res.json();
                    if (data && typeof data.seq === 'number') {
                        const prevSeq = this._lastSeq;
                        this._apply(data);
                        if (this._lastSeq !== prevSeq) lastFrameAt = Date.now();
                    }
                } catch (e) { /* transient / offline — retry next tick */ }
            };

            const loop = async () => {
                if (!document.hidden) await poll();
                const idle = this.state === 'idle'
                    && (Date.now() - lastFrameAt) > IDLE_AFTER;
                this._pollTimer = setTimeout(loop, idle ? IDLE_MS : ACTIVE_MS);
            };

            loop();

            // A hidden tab schedules but skips the fetch; on return, poll
            // right away so the display catches up without waiting a tick.
            // Handle kept so destroy() can unhook it (code-review #30).
            this._onVisibility = () => {
                if (!document.hidden) {
                    clearTimeout(this._pollTimer);
                    loop();
                }
            };
            document.addEventListener('visibilitychange', this._onVisibility);
        },
    };
}

document.addEventListener('alpine:init', () => {
    Alpine.data('customerDisplay', customerDisplay);
});

window.Alpine = Alpine;
Alpine.start();
