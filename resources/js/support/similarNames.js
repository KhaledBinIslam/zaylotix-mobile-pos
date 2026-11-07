/**
 * Khaled live: "Sujon" and "Sujon 3rd party" — two real, separate
 * suppliers he created without any warning either was close to the
 * other. The server's own guard only catches an EXACT (case-insensitive)
 * twin; this is the soft, client-side check that runs before a new
 * supplier is actually submitted, so the owner sees the near-miss and
 * can decide — never a hard block, since two real suppliers can
 * legitimately share a similar name ("Karim Traders" vs "Karim
 * Enterprise").
 *
 * Two names are "similar" when one, normalized, fully contains the
 * other (catches "Sujon" inside "Sujon 3rd party" in either direction),
 * or when they're a short edit-distance apart (catches a typo like
 * "Sujon" vs "Sujno") — never for an exact match, which the server
 * already refuses outright.
 */

const norm = (v) => String(v ?? '').toLowerCase().trim().replace(/\s+/g, ' ');

/** Classic Levenshtein — the number of single-character edits (insert/delete/substitute) to turn a into b. */
export function levenshtein(a, b) {
    const m = a.length;
    const n = b.length;
    if (m === 0) return n;
    if (n === 0) return m;

    let prev = Array.from({ length: n + 1 }, (_, j) => j);
    for (let i = 1; i <= m; i++) {
        const curr = [i];
        for (let j = 1; j <= n; j++) {
            curr[j] = a[i - 1] === b[j - 1]
                ? prev[j - 1]
                : 1 + Math.min(prev[j - 1], prev[j], curr[j - 1]);
        }
        prev = curr;
    }

    return prev[n];
}

/**
 * @returns the existing names (from `existing`) that look similar to
 * `name` — empty when `name` is blank, or matches nothing (including an
 * EXACT match, which is the server's own hard-block's job, not this one).
 */
export function findSimilarNames(name, existing) {
    const a = norm(name);
    if (!a) return [];

    return existing.filter((raw) => {
        const b = norm(raw);
        if (!b || b === a) return false; // exact match: the server's hard guard handles this
        if (a.includes(b) || b.includes(a)) return true;

        // a typo-sized edit distance, scaled to the shorter name's length
        // so "A" vs "B" (distance 1, but totally different) never matches
        const shortest = Math.min(a.length, b.length);
        const threshold = shortest <= 4 ? 1 : 2;

        return levenshtein(a, b) <= threshold;
    });
}
