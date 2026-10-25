import { refundLineAmounts, refundTotals } from '../lib/refund-calculation.js';

/**
 * Alpine factory for /admin/sales/{id}/refund.
 *
 * Drives the line picker — per-row refund qty (clamped to the
 * remaining returnable amount), per-row restock override, header
 * restock toggle, and the live totals card. The server is the
 * authority on tax allocation; the totals here are display-only and
 * use a proportional ratio of the original line's tax for each line.
 */
export function saleRefundPage({ rows = [], defaultMethodId = null } = {}) {
    return {
        // Each row gets a `refundQty` (number) and `restock` (null / bool).
        // Empty restock = "inherit the header flag"; explicit on/off
        // wins. Initial qty is the full remaining amount so a one-click
        // full refund needs no edits.
        rows: rows.map((r) => ({
            ...r,
            refundQty: parseFloat(r.remaining) || 0,
            restock:   null,
        })),

        defaultMethodId,
        headerRestock: true,

        money(v) {
            return window.posFormatMoney
                ? window.posFormatMoney(v)
                : Number(v || 0).toFixed(2);
        },

        /**
         * Trim trailing zeros from a stock quantity for display.
         * Quantities are stored at 4dp in the DB but cashiers expect
         * "10" not "10.0000" and "1.5" not "1.5000".
         */
        fmtQty(v) {
            const n = parseFloat(v) || 0;
            if (Number.isInteger(n)) return String(n);
            return String(n.toFixed(4)).replace(/0+$/, '').replace(/\.$/, '');
        },

        /**
         * Allocate the stored discount-net subtotal and tax by the returned
         * quantity. `unit_price` may already include GST, so it must remain
         * display-only here.
         */
        lineTotal(r) {
            return refundLineAmounts(r).grand;
        },

        get hasAnyQty() {
            return this.rows.some((r) => (parseFloat(r.refundQty) || 0) > 0);
        },

        get totals() {
            return refundTotals(this.rows);
        },
    };
}
