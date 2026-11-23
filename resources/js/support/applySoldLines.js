/**
 * Optimistic stock decrement for the POS tiles, applied the moment a
 * checkout succeeds (online or queued offline) — the server has already
 * taken the units off (PosController::checkout decrements inside the
 * sale's own transaction), this just makes the screen agree without
 * waiting for the next catalogue reload / poll.
 *
 * Mutates the product rows in place: `product.stock` drops by the line's
 * qty, and when the line names a variant, that variant's `stock` drops
 * too (products.stock is the live sum of its variants, so BOTH move —
 * same two-number shape the server keeps). Never clamped at zero: the
 * pool can legitimately be negative (oversell override), and the number
 * shown must be the arithmetic the server did, not a prettier one.
 *
 * Rows are de-duplicated by product id first, so passing both
 * `props.products` and `searchResults` (which share object references in
 * 'search' mode) can never double-count a line. Lines whose product isn't
 * in the rows are ignored — nothing on screen to update.
 *
 * @param {Array<object>} rows  product objects ({ id, stock, variants?: [{ id, stock }] })
 * @param {Array<{product_id:number, product_variant_id?:number|null, qty:number}>} lines
 * @returns {Array<object>} the same rows, for chaining
 */
export function applySoldLines(rows, lines) {
    const byId = new Map();
    for (const row of rows || []) {
        if (row && row.id != null && !byId.has(row.id)) byId.set(row.id, row);
    }

    for (const line of lines || []) {
        const qty = Number(line?.qty);
        if (!qty) continue;
        const product = byId.get(line.product_id);
        if (!product) continue;

        product.stock = Number(product.stock || 0) - qty;

        if (line.product_variant_id != null && Array.isArray(product.variants)) {
            const variant = product.variants.find((v) => v.id === line.product_variant_id);
            if (variant) variant.stock = Number(variant.stock || 0) - qty;
        }
    }

    return rows;
}
