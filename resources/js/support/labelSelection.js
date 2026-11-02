/**
 * Which rows of a product actually PRINT a distinct sticker.
 *
 * A shop that leaves "include size in barcode" off gets one sticker per
 * COLOUR — every size of that colour shares it (docs/ARTICLE_SERIES_PLAN
 * §9). "Select all variants" ignored those colour rows and ticked the
 * per-SIZE rows instead, one copy each. Since those sizes all print the
 * identical sticker, they collapsed back together: Khaled pressed it and
 * got two stickers where the shelf needed thirty-two, so he went back to
 * doing them one at a time.
 *
 * Copies default to the stock behind each row, because a sticker goes on
 * a pair, not on a row in a list — still editable on the row itself.
 *
 * Pure, so the rule that decides this can be tested without a browser.
 */
export function printableRowsOf(product) {
    const colours = product?.color_barcodes ?? [];
    if (colours.length) {
        return colours.map((cb) => ({ key: 'c:' + cb.id, copies: copiesFor(cb.stock) }));
    }

    const variants = product?.variants ?? [];
    if (variants.length) {
        return variants
            .filter((v) => v.barcode)
            .map((v) => ({ key: 'v:' + v.id, copies: copiesFor(v.stock) }));
    }

    // a plain product with no sizes at all — its own barcode is the sticker
    return product?.barcode ? [{ key: 'p:' + product.id, copies: copiesFor(product.stock) }] : [];
}

/** One sticker per pair on the shelf, but never zero — an out-of-stock row still has to be printable on demand. */
function copiesFor(stock) {
    const n = Math.floor(Number(stock) || 0);

    return n > 0 ? n : 1;
}
