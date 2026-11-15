// Pure, dependency-free matching logic for the app-wide feature/menu
// search (Home.vue + More.vue) — kept out of the .vue component so it can
// be unit-tested with plain arrays instead of mounting a real page, and so
// "does it actually filter per-shop dynamically" is provable without a
// browser: pass it two shops' different `navGroups` (different features/
// permissions => different `show` flags) and assert the results differ.

/** Flattens useNavItems()'s grouped shape into one list, dropping any item this user/shop can't actually reach (`show: false`). */
export function flattenSearchable(navGroups, extraItems = []) {
    const fromGroups = navGroups.flatMap((g) => g.items.filter((i) => i.show).map((i) => ({
        key: i.key, label: i.label, navLabel: i.navLabel, href: i.href, icon: i.icon, groupLabel: g.label,
    })));
    const fromExtra = extraItems.filter((i) => i.show).map((i) => ({
        key: i.key, label: i.label, navLabel: i.navLabel, href: i.href, icon: i.icon, groupLabel: i.groupLabel,
    }));
    return [...fromGroups, ...fromExtra];
}

function searchText(item) {
    return `${item.label} ${item.navLabel || ''} ${item.key}`.toLowerCase();
}

/** Classic iterative Levenshtein edit distance — insert/delete/substitute, each costing 1. Works character-by-character, which is fine for Bangla here (BMP code points, no surrogate pairs in practice for this script). */
function levenshtein(a, b) {
    const m = a.length;
    const n = b.length;
    if (m === 0) return n;
    if (n === 0) return m;
    let prev = Array.from({ length: n + 1 }, (_, j) => j);
    for (let i = 1; i <= m; i++) {
        const cur = [i];
        for (let j = 1; j <= n; j++) {
            cur[j] = a[i - 1] === b[j - 1]
                ? prev[j - 1]
                : 1 + Math.min(prev[j - 1], prev[j], cur[j - 1]);
        }
        prev = cur;
    }
    return prev[n];
}

// how many spelling mistakes a word of this length is allowed to have and
// still count as a match — scales with length so a short word (where one
// typo changes everything) stays strict, and a long word tolerates more
function typoBudget(len) {
    if (len <= 3) return 0;
    if (len <= 6) return 1;
    return 2;
}

/**
 * Finds the best (smallest) edit distance between `query` and ANY
 * contiguous word in `text` (split on spaces/slashes/parentheses/punctuation
 * — Bangla text uses the same ASCII separators here), so a multi-word label
 * like "বারকোড লেবেল" can still typo-match against just "লেবেল".
 */
function bestWordDistance(text, query) {
    const words = text.split(/[\s/()।,-]+/).filter(Boolean);
    let best = Infinity;
    for (const w of words) {
        // only compare against words in the same rough length range — an
        // 18-character word "fuzzy-matching" a 4-character query at
        // distance 2 would be a false positive, not a real typo
        if (Math.abs(w.length - query.length) > typoBudget(query.length) + 1) continue;
        const d = levenshtein(w, query);
        if (d < best) best = d;
    }
    return best;
}

/**
 * Case-insensitive match against the label, its short nav label, and its
 * stable English `key` (so "barcode" finds "বারকোড লেবেল" even on a Bangla
 * UI) — exact substring matches rank first, then a typo-tolerant fallback
 * (Khaled's explicit ask: "banan vul holeo ... search korle cole ase")
 * catches a misspelled word that would otherwise find nothing.
 */
export function searchItems(items, query) {
    const q = query.trim().toLowerCase();
    if (!q) return [];

    const exact = items.filter((i) => searchText(i).includes(q));
    if (q.length < 3) return exact; // too short for fuzzy to mean anything

    const exactKeys = new Set(exact.map((i) => i.key));
    const budget = typoBudget(q.length);
    const fuzzy = items.filter((i) => !exactKeys.has(i.key) && bestWordDistance(searchText(i), q) <= budget);

    return [...exact, ...fuzzy];
}
