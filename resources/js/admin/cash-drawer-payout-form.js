/**
 * Pay-out form on the shift page — keeps the amount inside what the drawer
 * actually holds.
 *
 * The hard rule lives on the server (`App\Actions\Shifts\RecordCashDrawerEntry`
 * refuses any pay-out above the shift's expected cash). This is the part that
 * stops the cashier finding out only after they've filled the whole form: the
 * ceiling is shown up front, the input carries it as `max`, and the submit
 * button locks while the typed amount is over it.
 *
 * `available` is server-rendered rather than fetched — the shift page reloads
 * after every drawer movement, so the figure on screen is never stale.
 */
export function cashDrawerPayOutForm({ available = null } = {}) {
    return {
        category: 'expense',
        amount:   '',

        /** 4dp decimal-string from the server; null = unknown, so no cap. */
        available: available === null || available === '' ? null : Number(available),

        /** What the input's `max` should be. Never negative — a drawer that
         *  somehow went below zero caps at zero, not at a negative maximum
         *  the browser would reject every entry against. */
        get maxAmount() {
            if (this.available === null) return null;
            return Math.max(0, this.available);
        },

        /** True once the typed amount exceeds the drawer. The 0.00005 slack
         *  matches the 4dp scale everything else in the app settles to, so a
         *  float artefact can't trip the warning on an exact-to-the-paisa
         *  "empty the drawer" pay-out. */
        get overDrawer() {
            if (this.available === null) return false;
            const amt = Number(this.amount);
            if (!Number.isFinite(amt) || amt <= 0) return false;
            return amt - this.available > 0.00005;
        },

        /** Fills the amount with everything the drawer holds — the common
         *  case for a bank-deposit or end-of-day move-to-safe pay-out. */
        takeAll() {
            if (this.maxAmount === null) return;
            this.amount = this.maxAmount.toFixed(2);
        },

        money(value) {
            return window.posFormatMoney
                ? window.posFormatMoney(value)
                : Number(value || 0).toFixed(2);
        },
    };
}
