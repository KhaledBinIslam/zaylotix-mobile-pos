/**
 * Shared by Pos/Index.vue and Clothing/Pos.vue — a variant product's card
 * used to show only its base `price`/no stock total at all (see
 * docs/FIX_PLAN.md's "price/stock missing during order/sale" fix). Pure,
 * framework-free so both pages (and this test file) can use the exact
 * same numbers instead of two independently-maintained copies.
 */

/**
 * The min/max price across a product's variants, falling back to the
 * product's own (discounted, if any) price when it has none. Returns
 * `min === max` when every variant costs the same (the common case) —
 * the caller decides whether that renders as one number or a range.
 */
export function variantPriceBounds(product) {
    const basePrice = Number(product?.discount_price ?? product?.price ?? 0);
    const prices = (product?.variants || []).map((v) => Number(v.price ?? product.price));
    if (!prices.length) return { min: basePrice, max: basePrice };

    return { min: Math.min(...prices), max: Math.max(...prices) };
}

/** Sum of every variant's own stock — a variant product's base `stock` is always 0, so this is the real total. */
export function variantStockTotal(product) {
    return (product?.variants || []).reduce((sum, v) => sum + Number(v.stock || 0), 0);
}
