/**
 * Mirrors app/Support/SearchMatcher.php exactly (shared fixture at
 * tests/fixtures/search_match_cases.json). Operates on ALREADY-normalized
 * text (see searchNormalize.js) — never re-normalizes here, so callers
 * control that step once, consistently.
 *
 * Layered ranking (docs/FIX_PLAN.md's ধাপ ২.খ): lower tier number = better
 * match. Tier 1 (barcode) is decided by the caller, who knows which field
 * is actually a barcode — this module only scores free text.
 */
export const MATCH_TIER = {
    BARCODE: 1,
    PREFIX: 2,
    WORD_PREFIX: 3,
    SUBSTRING: 4,
    TYPO_TOLERANT: 5,
    PHONETIC: 6,
};

function splitWords(text) {
    return text.split(' ').filter(Boolean);
}

/** Classic Levenshtein edit distance over Unicode codepoints (not grapheme clusters — a reasonable, simple approximation for short search words). */
export function levenshtein(a, b) {
    const ca = Array.from(a);
    const cb = Array.from(b);
    const m = ca.length;
    const n = cb.length;
    if (m === 0) return n;
    if (n === 0) return m;

    const dp = Array.from({ length: m + 1 }, () => new Array(n + 1).fill(0));
    for (let i = 0; i <= m; i++) dp[i][0] = i;
    for (let j = 0; j <= n; j++) dp[0][j] = j;
    for (let i = 1; i <= m; i++) {
        for (let j = 1; j <= n; j++) {
            dp[i][j] = ca[i - 1] === cb[j - 1]
                ? dp[i - 1][j - 1]
                : 1 + Math.min(dp[i - 1][j], dp[i][j - 1], dp[i - 1][j - 1]);
        }
    }

    return dp[m][n];
}

/** Shorter words tolerate less drift — a 1-edit typo on a 3-letter word changes its meaning more than on a 10-letter one. */
function typoThreshold(word) {
    return Array.from(word).length <= 4 ? 1 : 2;
}

function fuzzyWordsMatch(queryWords, textWords) {
    if (!queryWords.length) return false;

    return queryWords.every((qw) => textWords.some((tw) => levenshtein(qw, tw) <= typoThreshold(qw)));
}

/**
 * Second-tier phonetic equivalence (ি/ী, ু/ূ, শ/ষ/স, ন/ণ, জ/য — ধাপ ২.খ.৬)
 * — deliberately NOT part of searchNormalize.js's strict pass, since these
 * are genuinely different spellings that happen to sound alike, not the
 * same text in two encodings. The negative lookahead on য় keeps this from
 * touching the already-decomposed য়-sequence (য + nukta) that
 * searchNormalize.js produces.
 */
export function phoneticFold(text) {
    return text
        .replace(/ী/g, 'ি') // ী -> ি
        .replace(/ূ/g, 'ু') // ূ -> ু
        .replace(/[শষ]/g, 'স') // শ/ষ -> স
        .replace(/ণ/g, 'ন') // ণ -> ন
        .replace(/য(?!়)/g, 'জ'); // bare য (not য়) -> জ
}

function phoneticWordsMatch(queryWords, textWords) {
    if (!queryWords.length) return false;
    const foldedQuery = queryWords.map(phoneticFold);
    const foldedText = textWords.map(phoneticFold);

    return foldedQuery.every((qw) => foldedText.some((tw) => tw.startsWith(qw) || tw.includes(qw)));
}

/**
 * `query` and `text` must already be normalizeSearch()-ed. Word order in
 * `query` never matters from tier 3 onward ("সস টমেটো" ranks the same as
 * "টমেটো সস" against "টমেটো সস").
 */
export function searchMatchTier(query, text) {
    if (!query || !text) return null;
    if (text.startsWith(query)) return MATCH_TIER.PREFIX;

    const queryWords = splitWords(query);
    const textWords = splitWords(text);

    if (queryWords.length && queryWords.every((qw) => textWords.some((tw) => tw.startsWith(qw)))) {
        return MATCH_TIER.WORD_PREFIX;
    }
    if (text.includes(query)) return MATCH_TIER.SUBSTRING;
    if (fuzzyWordsMatch(queryWords, textWords)) return MATCH_TIER.TYPO_TOLERANT;
    if (phoneticWordsMatch(queryWords, textWords)) return MATCH_TIER.PHONETIC;

    return null;
}

/**
 * Convenience for a product with several searchable fields (name, name_en,
 * generic_name, keywords, …) plus a separate exact-barcode check — returns
 * the single best (lowest) tier across all of them, or null if nothing matched.
 */
export function bestMatchTier(query, fields, { barcodeExact = false } = {}) {
    if (barcodeExact) return MATCH_TIER.BARCODE;

    let best = null;
    for (const field of fields) {
        const tier = searchMatchTier(query, field || '');
        if (tier !== null && (best === null || tier < best)) best = tier;
    }

    return best;
}
