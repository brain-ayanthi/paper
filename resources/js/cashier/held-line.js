/**
 * Rebuild a cashier cart line from the server's held-sale snapshot.
 *
 * Tax metadata must travel with the saved unit price. Without it, the
 * resumed cart treats the line as untaxed even though checkout later uses
 * the product's tax group, making the displayed total incorrectly show only
 * the base amount.
 */
export function cartLineFromHeldItem(item) {
    return {
        product_id:     item.product_id,
        variant_id:     item.variant_id ?? null,
        name:           item.name,
        sku:            item.sku,
        unit:           item.unit ?? 'pc',
        unit_price:     item.unit_price,
        tax_group_id:   item.tax_group_id ?? null,
        tax_rate_total: Number.parseFloat(item.tax_rate_total ?? 0) || 0,
        tax_inclusive:  Boolean(item.tax_inclusive),
        tax_taxable:    item.tax_taxable !== false,
        on_hand:        0,
        image_url:      null,
        variant_label:  item.variant_label ?? null,
    };
}
