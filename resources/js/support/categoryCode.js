/**
 * A category with no code gives its article NO serial: the serial preview and
 * the barcode both need the category's short code ({CAT}-{SERIAL}), and the
 * form only showed an empty box to type one into. Baby Park's Sandals / Kids'
 * Shoes / Men's Shoes / School Shoes, and all 98 of SEAMAN's categories, had
 * none — so "pick a category and the serial just comes" worked for some
 * categories and silently didn't for others.
 *
 * suggestCategoryCode() gives the form something sensible to pre-fill the
 * moment such a category is picked, still editable. English letters/digits
 * only, at most 5 (the same rule the field enforces: Code128 cannot encode
 * Bengali). It prefers a code no OTHER category already uses, so a freshly
 * auto-coded category does not silently join someone else's serial series
 * (sharing a code stays possible, but only when typed on purpose).
 *
 * @param {{ name?: string, name_en?: string }} category
 * @param {string[]} existingCodes codes already in use by the shop's categories
 * @returns {string}
 */
export function suggestCategoryCode(category, existingCodes = []) {
    const taken = new Set((existingCodes || []).map((c) => String(c || '').trim().toUpperCase()).filter(Boolean));

    const english = String(category?.name_en || category?.name || '')
        .normalize('NFKD')
        .replace(/['’`]/g, '') // "Men's" is one word, not "Men" + "s"
        .replace(/[^A-Za-z0-9\s&/-]/g, ' ')
        .trim();
    const words = english.split(/[\s&/-]+/).filter(Boolean);

    let base;
    if (words.length >= 2) {
        base = words.map((w) => w[0]).join('').slice(0, 4);
    } else if (words.length === 1) {
        base = words[0].slice(0, 3);
    } else {
        base = 'C'; // Bengali-only name: nothing to derive from
    }
    base = base.toUpperCase().replace(/[^A-Z0-9]/g, '') || 'C';

    if (!taken.has(base)) return base;
    for (let n = 2; n < 100; n++) {
        const candidate = (base.slice(0, 5 - String(n).length) + n).toUpperCase();
        if (!taken.has(candidate)) return candidate;
    }
    return base;
}
