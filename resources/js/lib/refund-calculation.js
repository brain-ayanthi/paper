/**
 * Display-only refund allocation shared by the admin and cashier screens.
 *
 * `line_subtotal` is the original sale line's stored ex-tax, discount-net
 * amount in both exclusive and inclusive pricing modes. Allocating that
 * value (rather than qty x unit_price) mirrors RecordSaleReturn and avoids
 * adding tax twice when unit_price already includes GST.
 */
export function refundLineAmounts(row = {}) {
    const qty = parseFloat(row.refundQty) || 0;
    const originalQty = parseFloat(row.quantity) || 0;

    if (qty <= 0 || originalQty <= 0) {
        return { subtotal: 0, tax: 0, grand: 0 };
    }

    const ratio = qty / originalQty;
    const subtotal = (parseFloat(row.line_subtotal) || 0) * ratio;
    const tax = (parseFloat(row.tax_amount) || 0) * ratio;

    return {
        subtotal,
        tax,
        grand: subtotal + tax,
    };
}

export function refundTotals(rows = []) {
    return rows.reduce((totals, row) => {
        const line = refundLineAmounts(row);

        totals.subtotal += line.subtotal;
        totals.tax += line.tax;
        totals.grand += line.grand;

        return totals;
    }, { subtotal: 0, tax: 0, grand: 0 });
}
