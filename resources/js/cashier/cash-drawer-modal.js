/**
 * Cash in / out from the till.
 *
 * Until this existed the ONLY cash-drawer UI was on the admin shift page, so a
 * cashier paying a courier out of the drawer had to leave the till screen to
 * record it — and in practice didn't, which is exactly how a shift ends up
 * short at close with nothing to point at.
 *
 * Posts to the same endpoint the admin panel uses
 * (`POST /admin/shifts/{shift}/cash-drawer`), so there is one server-side
 * authority for drawer movements. A pay-out categorised as `expense` books a
 * real expenses row too — see App\Actions\Shifts\RecordPayOutExpense.
 *
 * Direction is a two-button toggle rather than a signed amount, per the
 * system-wide "no negative number inputs" convention.
 */

import { posGet, posPost } from '../lib/http.js';

export function cashDrawerModal(config = {}) {
    return {
        open: false,

        recordUrl:  config.recordUrl ?? null,
        availableUrl: config.availableUrl ?? null,
        canPayIn:   Boolean(config.canPayIn),
        canPayOut:  Boolean(config.canPayOut),
        categories: config.categories ?? [],

        /** Cash the drawer holds, refreshed each time the modal opens.
         *  Unlike the admin shift page this screen doesn't reload between
         *  sales, so a value captured at page load would be wrong by the
         *  time anyone opens this. null = not known yet / no open shift. */
        available:          null,
        availableFormatted: '',

        // 'pay_in' | 'pay_out'
        direction: 'pay_out',
        amount:    '',
        category:  'expense',
        expenseCategoryId: '',
        reason:    '',
        submitting: false,

        get isPayOut() {
            return this.direction === 'pay_out';
        },

        /** Only a pay-out categorised as an expense books an expenses row. */
        get booksExpense() {
            return this.isPayOut && this.category === 'expense';
        },

        /** Cap for the amount input on a pay-out. Pay-ins are unbounded —
         *  money going IN can't overdraw anything. */
        get maxAmount() {
            if (!this.isPayOut || this.available === null) return null;
            return Math.max(0, this.available);
        },

        /** True once a pay-out exceeds the drawer. Mirrors the server rule in
         *  `App\Actions\Shifts\RecordCashDrawerEntry` so the cashier is told
         *  before they submit, not after. */
        get overDrawer() {
            if (this.maxAmount === null) return false;
            const amt = Number(this.amount);
            if (!Number.isFinite(amt) || amt <= 0) return false;
            return amt - this.maxAmount > 0.00005;
        },

        /** Pull the current drawer figure. Fails soft: if the request errors
         *  we simply don't cap client-side — the server still refuses an
         *  overdrawing pay-out, so the money rule holds either way. */
        async refreshAvailable() {
            if (!this.availableUrl) return;
            try {
                const { data } = await posGet(this.availableUrl);
                this.available = data?.available === null || data?.available === undefined
                    ? null
                    : Number(data.available);
                this.availableFormatted = data?.available_formatted ?? '';
            } catch (e) {
                this.available = null;
                this.availableFormatted = '';
            }
        },

        show() {
            // Open on whichever direction this user is allowed to record, so
            // a pay-in-only operator doesn't land on a disabled form.
            this.direction = this.canPayOut ? 'pay_out' : 'pay_in';
            this.amount = '';
            this.reason = '';
            this.category = 'expense';
            this.expenseCategoryId = '';
            this.open = true;
            this.$nextTick(() => this.$refs.amountInput?.focus());
            this.refreshAvailable();
        },

        close() {
            this.open = false;
        },

        setDirection(dir) {
            this.direction = dir;
        },

        async submit() {
            if (this.submitting) return;

            const amount = Number(this.amount);
            if (!Number.isFinite(amount) || amount <= 0) {
                this.$store.toasts?.push({ type: 'error', message: this.$el.dataset.msgAmount });
                return;
            }
            if (!String(this.reason).trim()) {
                this.$store.toasts?.push({ type: 'error', message: this.$el.dataset.msgReason });
                return;
            }
            // Belt to the server's braces — RecordCashDrawerEntry refuses this
            // anyway, but a toast beats a round-trip and a red 422.
            if (this.overDrawer) {
                this.$store.toasts?.push({ type: 'error', message: this.$el.dataset.msgOver });
                return;
            }

            this.submitting = true;
            try {
                const { data } = await posPost(this.recordUrl, {
                    type:     this.direction,
                    amount:   this.amount,
                    reason:   this.reason,
                    // Category rides along on pay-outs only; the server
                    // ignores it otherwise.
                    category: this.isPayOut ? this.category : null,
                    expense_category_id: this.booksExpense && this.expenseCategoryId
                        ? this.expenseCategoryId
                        : null,
                });

                this.$store.toasts?.push({
                    type:    'success',
                    message: data?.message || this.$el.dataset.msgDone,
                });
                this.close();
            } catch (e) {
                // 422s are surfaced by the interceptor's toast; a 5xx is
                // toasted globally. Nothing to add — just stay open so the
                // cashier can correct and resubmit.
            } finally {
                this.submitting = false;
            }
        },
    };
}
