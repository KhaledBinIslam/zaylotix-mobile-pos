// docs/ARTICLE_SERIES_PLAN.md §2 — the client-side mirror of
// App\Support\ArticleBarcode::encodeCost(), used only to show a live
// barcode preview while the article form is being filled in. The barcode
// that actually gets SAVED is always built server-side from the shop's own
// map; nothing here is ever sent back or trusted.

/** Every cost code ends with this — see ArticleBarcode::COST_SUFFIX. It marks the end of the code, so no digit may be mapped to it. */
export const COST_SUFFIX = 'Z';

/**
 * Encodes a cost into its digit-letter code, or null when any of its
 * digits has no letter yet — the same "say so rather than guess" contract
 * the server keeps, so the preview can honestly show that the cost-code
 * segment can't be built yet instead of inventing one.
 *
 * `map` is digit-as-string -> letter, exactly the shape
 * Shop::costCodeDigitMap() serializes to.
 */
export function encodeCost(map, cost) {
    if (cost === null || cost === undefined || cost === '') return null;

    const digits = String(Math.trunc(Math.abs(Number(cost))));
    if (!/^\d+$/.test(digits)) return null;

    let code = '';
    for (const digit of digits) {
        const letter = map?.[digit];
        if (!letter) return null;
        code += letter;
    }

    return code + COST_SUFFIX;
}
