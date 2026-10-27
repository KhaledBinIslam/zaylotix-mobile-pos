/**
 * docs/ARTICLE_SERIES_PLAN.md §9 — mirrors App\Support\ArticleBarcode's
 * COLOR_CODE_SUGGESTIONS/suggestColorCode() exactly, so the color-code
 * settings page can suggest a code as the owner types a new color name
 * without a network round trip. Kept as its own small pure function (like
 * productDisplay.js) so both sides can be tested against the same examples.
 */
export const COLOR_CODE_SUGGESTIONS = {
    SILVER: 'SIL', KHAKI: 'KHK', GOLDEN: 'GLD', WHITE: 'WHT',
    BLACK: 'BLK', BROWN: 'BRN', COFFEE: 'COF', MAROON: 'MRN', GREY: 'GRY',
};

export function suggestColorCode(color) {
    const key = (color || '').trim().toUpperCase();
    if (COLOR_CODE_SUGGESTIONS[key]) return COLOR_CODE_SUGGESTIONS[key];

    return key.replace(/[^A-Z]/g, '').slice(0, 3);
}
