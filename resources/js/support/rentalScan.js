// Pure, dependency-free barcode-matching logic for the Rental screens'
// scanners (Rentals/Index.vue's "pick a product to rent" and Show.vue's
// "pick which item is being returned") — kept out of the .vue components
// so it can be unit-tested with plain arrays instead of a real camera/
// mounted page. Same barcode precedence every scanner in the app uses: a
// variant's own barcode is checked before the plain product barcode.

/**
 * `products` is the full rentable catalog (Rentals/Index.vue's own
 * `products` prop — product-level barcode + each variant's own barcode).
 * Returns one of:
 *  - { status: 'variant', productId, variantId }
 *  - { status: 'product', productId }               (no variants at all)
 *  - { status: 'needs_variant', productId }          (has variants, scanned the bare product barcode)
 *  - { status: 'not_found' }
 */
export function matchRentableProduct(products, code) {
    for (const p of products) {
        const v = p.variants?.find((variant) => variant.barcode === code);
        if (v) {
            return { status: 'variant', productId: p.id, variantId: v.id };
        }
    }
    const product = products.find((p) => p.barcode === code);
    if (product) {
        return product.variants?.length
            ? { status: 'needs_variant', productId: product.id }
            : { status: 'product', productId: product.id };
    }
    return { status: 'not_found' };
}

/**
 * `rawItems` is a single rental's own item list (Rentals/Show.vue's
 * `rawItems` prop). Returns one of:
 *  - { status: 'ok', item }
 *  - { status: 'fully_returned', item }
 *  - { status: 'not_found' }
 */
export function matchRentalReturnItem(rawItems, code) {
    const item = rawItems.find((i) => i.barcode === code);
    if (!item) {
        return { status: 'not_found' };
    }
    if (item.outstanding_qty <= 0) {
        return { status: 'fully_returned', item };
    }
    return { status: 'ok', item };
}

/**
 * Returns/Index.vue's own scan-to-select — same shape as
 * matchRentalReturnItem but against a SALE's own `items` list, keyed by
 * `returnable_qty` (how much of that line hasn't already been returned,
 * possibly at a sibling branch) instead of a rental's `outstanding_qty`.
 * Returns one of:
 *  - { status: 'ok', item }
 *  - { status: 'fully_returned', item }
 *  - { status: 'not_found' }
 */
export function matchSaleReturnItem(items, code) {
    const item = items.find((i) => i.barcode === code);
    if (!item) {
        return { status: 'not_found' };
    }
    if (item.returnable_qty <= 0) {
        return { status: 'fully_returned', item };
    }
    return { status: 'ok', item };
}
