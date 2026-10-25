/**
 * Snapshot the catalog's active-store shelf location onto a cart line.
 *
 * Keeping this at the single cart-line funnel covers tile taps, barcode and
 * scale scans, variants, batches, and resumed holds. An explicit location on
 * the incoming line wins so a future server payload can preserve historical
 * location text even when the live catalog has since changed.
 */
export function withCartLineLocation(line, products = []) {
    const product = products.find((candidate) =>
        String(candidate?.id ?? '') === String(line?.product_id ?? ''));

    const lineLocations = Array.isArray(line?.locations) && line.locations.length
        ? line.locations
        : [];
    const productLocations = Array.isArray(product?.locations) && product.locations.length
        ? product.locations
        : [];
    const locations = lineLocations.length ? lineLocations : productLocations;

    const lineLocation = typeof line?.location === 'string' ? line.location.trim() : '';
    const productLocation = typeof product?.location === 'string' ? product.location.trim() : '';
    const location = lineLocation
        || (typeof locations[0]?.label === 'string' ? locations[0].label.trim() : '')
        || productLocation;

    return {
        ...line,
        location,
        locations,
    };
}
