/**
 * Client-side product search for pages that already hold the full product
 * list (Barcode Labels first — its old filter was a bare includes() on
 * name/barcode only, so "suit radium", a colour, a brand or a category
 * found nothing). One helper, so every page that filters in the browser
 * matches the same way the Stock page's server search feels:
 *
 * - live from the 1st character, case-insensitive
 * - multi-word, ANY order: every typed word must appear somewhere in the
 *   product's haystack (name, bangla/english, category, brand, supplier,
 *   generic name, barcodes/ZV codes, every variant's size & colour)
 * - ranked: whole-query prefix of the name first, then name-word prefix,
 *   then everything else that matches — stable within a tier
 */

const norm = (v) => String(v ?? '').toLowerCase().trim();

/** Everything about one product a cashier might type — built once per product per search. */
export function productHaystack(p) {
    const parts = [
        p.name, p.name_en, p.generic_name, p.company,
        p.category_name, p.brand_name, p.supplier_name,
        p.barcode,
    ];
    // Khaled live: "sujon search korchi kichu asena" — p.supplier_name
    // above is the product's single, one-time field; a real supplier that
    // only exists on a LINE (Part 1) was never in the haystack at all.
    for (const line of p.sources || []) {
        parts.push(line.supplier_name);
    }
    for (const v of p.variants || []) {
        parts.push(v.size, v.color, v.barcode, v.scan_code, v.brand_name);
        for (const line of v.sources || []) {
            parts.push(line.supplier_name);
        }
    }
    for (const cb of p.color_barcodes || []) {
        parts.push(cb.color, cb.barcode, cb.scan_code);
    }

    return norm(parts.filter(Boolean).join(' '));
}

/**
 * Does this product carry a line — on the product itself, or on any of its
 * variants — from this exact supplier? Falls back to the product's own
 * single supplier_id when it has no lines at all (a shop without
 * source_lines, or a product Part 1's backfill hasn't touched yet), so the
 * filter still works for data that predates per-line suppliers.
 */
export function productHasSupplier(p, supplierId) {
    const ownLines = p.sources || [];
    const variantLines = (p.variants || []).flatMap((v) => v.sources || []);
    const allLines = [...ownLines, ...variantLines];

    // a product with real lines is judged ONLY by them, even if none of
    // those lines happen to match this supplier — falling back to the
    // stale single supplier_id here would un-filter exactly the lots this
    // filter exists to tell apart (Khaled: "ekhon sob Bulu dekhacche")
    if (allLines.length) return allLines.some((s) => s.supplier_id === supplierId);

    return p.supplier_id === supplierId;
}

/**
 * @returns the matching products, best first; the original array when the
 * query is blank (so "no search" stays byte-identical to no filtering).
 */
export function searchProducts(products, query) {
    const q = norm(query);
    if (!q) return products;

    const words = q.split(/\s+/).filter(Boolean);

    const ranked = [];
    for (let i = 0; i < products.length; i++) {
        const p = products[i];
        const hay = productHaystack(p);
        if (!words.every((w) => hay.includes(w))) continue;

        const name = norm(p.name);
        const nameEn = norm(p.name_en);
        let tier = 2;
        if (name.startsWith(q) || nameEn.startsWith(q)) tier = 0;
        else if (name.split(/\s+/).some((w) => w.startsWith(words[0]))) tier = 1;

        ranked.push({ p, tier, i });
    }

    ranked.sort((a, b) => a.tier - b.tier || a.i - b.i);

    return ranked.map((r) => r.p);
}
