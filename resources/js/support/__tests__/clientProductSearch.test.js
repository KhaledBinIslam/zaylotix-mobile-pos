import { describe, it, expect } from 'vitest';
import { searchProducts, productHaystack, productHasSupplier, variantHasSupplier } from '../clientProductSearch';

/**
 * Barcode Labels' old filter was a bare includes() on name/barcode only —
 * "boi" found nothing, a colour/brand/supplier/category typed in found
 * nothing. These pin the Part 2 request: live from character 1,
 * case-insensitive, multi-word any order, matches anywhere a cashier
 * might look (variant size/colour, category, brand, supplier, barcode/ZV
 * codes), best matches first.
 */
const PRODUCTS = [
    {
        id: 1, name: 'Boiler Suit with Radium', name_en: '', barcode: 'ZP1',
        category_name: 'Suit', brand_name: null, supplier_name: 'Manik',
        variants: [
            { id: 11, size: 'M', color: 'Orange', barcode: 'ZV11', scan_code: 'ZV11' },
            { id: 12, size: 'XXL', color: 'Navy Blue', barcode: 'ZV12', scan_code: 'ZV12' },
        ],
    },
    {
        id: 2, name: 'Lotus Safety Shoe', name_en: 'Lotus Safety Shoe', barcode: 'ZP2',
        category_name: 'Safety Shoe', brand_name: 'Lotus', supplier_name: 'Jubilee',
        variants: [{ id: 21, size: '42', color: null, barcode: 'ZV21', scan_code: 'ZV21' }],
    },
    {
        id: 3, name: 'Power X Safety Shoe', name_en: '', barcode: 'ZP3',
        category_name: 'Safety Shoe', brand_name: 'Power X', supplier_name: 'Safety Gallery',
        variants: [],
    },
];

describe('searchProducts', () => {
    it('returns everything, unfiltered, for a blank query', () => {
        expect(searchProducts(PRODUCTS, '')).toBe(PRODUCTS); // same array — byte-identical to "no search"
        expect(searchProducts(PRODUCTS, '   ')).toEqual(PRODUCTS);
    });

    it('matches from the very first character, live-typing style', () => {
        expect(searchProducts(PRODUCTS, 'bo').map((p) => p.id)).toEqual([1]);
        expect(searchProducts(PRODUCTS, 'boi').map((p) => p.id)).toEqual([1]);
    });

    it('is case-insensitive and matches anywhere in the name, not only a prefix', () => {
        expect(searchProducts(PRODUCTS, 'RADIUM').map((p) => p.id)).toEqual([1]);
        expect(searchProducts(PRODUCTS, 'safety').map((p) => p.id).sort()).toEqual([2, 3]);
    });

    it('matches multiple words in ANY order', () => {
        expect(searchProducts(PRODUCTS, 'suit radium').map((p) => p.id)).toEqual([1]);
        expect(searchProducts(PRODUCTS, 'radium suit').map((p) => p.id)).toEqual([1]);
        expect(searchProducts(PRODUCTS, 'x power').map((p) => p.id)).toEqual([3]);
    });

    it('matches a variant size or colour', () => {
        expect(searchProducts(PRODUCTS, 'navy blue').map((p) => p.id)).toEqual([1]);
        expect(searchProducts(PRODUCTS, 'xxl').map((p) => p.id)).toEqual([1]);
        expect(searchProducts(PRODUCTS, '42').map((p) => p.id)).toEqual([2]);
    });

    it('matches category, brand and supplier', () => {
        expect(searchProducts(PRODUCTS, 'lotus').map((p) => p.id)).toEqual([2]);
        expect(searchProducts(PRODUCTS, 'jubilee').map((p) => p.id)).toEqual([2]);
        expect(searchProducts(PRODUCTS, 'manik').map((p) => p.id)).toEqual([1]);
    });

    it('matches a barcode or a variant ZV scan code', () => {
        expect(searchProducts(PRODUCTS, 'ZP3').map((p) => p.id)).toEqual([3]);
        expect(searchProducts(PRODUCTS, 'zv12').map((p) => p.id)).toEqual([1]);
    });

    it('ranks a name-prefix match before a contains-only match', () => {
        const results = searchProducts(PRODUCTS, 'safety');
        // 'Lotus Safety Shoe' and 'Power X Safety Shoe' both only CONTAIN
        // 'safety' (neither starts with it) — tier 2 for both, so original
        // order (id 2 before 3) is preserved, never alphabetically reshuffled
        expect(results.map((p) => p.id)).toEqual([2, 3]);

        const prefixed = searchProducts(PRODUCTS, 'lotus');
        expect(prefixed[0].id).toBe(2); // name starts with the query
    });

    it('returns nothing for a query that matches no product, not an error', () => {
        expect(searchProducts(PRODUCTS, 'zzzznotfound')).toEqual([]);
    });

    it('productHaystack lower-cases and joins every searchable field', () => {
        const hay = productHaystack(PRODUCTS[0]);
        expect(hay).toContain('boiler suit with radium');
        expect(hay).toContain('navy blue');
        expect(hay).toContain('manik');
        expect(hay).toContain('zv12');
    });

    /**
     * Khaled live: "sujon search korchi kichu asena" — the product's own
     * supplier_name (above) was still 'Manik' (set once, long ago), but
     * the real stock on one of its variants now came from Sujon via a
     * Part 1 line. Typing the LINE's supplier must find it too.
     */
    it('matches a supplier that only exists on a line, not the product\'s own field', () => {
        const withLines = { ...PRODUCTS[0], variants: [
            { ...PRODUCTS[0].variants[0], sources: [{ id: 101, supplier_id: 7, supplier_name: 'Sujon' }] },
            PRODUCTS[0].variants[1],
        ] };
        expect(searchProducts([withLines], 'sujon').map((p) => p.id)).toEqual([1]);
        expect(productHaystack(withLines)).toContain('sujon');
    });
});

/**
 * Khaled live: the barcode-print picker showed one stale supplier badge per
 * product (its single supplier_id, set once) even once Part 1 lines let a
 * product carry several real suppliers — "ekhon sob Bulu dekhacche". The
 * filter matches the ACTUAL lines (product's own, or any variant's), and
 * only falls back to the single field for a product with no lines at all.
 */
describe('productHasSupplier', () => {
    const bulu = { id: 1, name: 'Boiler Suit', supplier_id: 7, variants: [
        { id: 11, sources: [{ id: 101, supplier_id: 7 }] },
        { id: 12, sources: [{ id: 102, supplier_id: 9 }] }, // a different lot, different supplier
    ] };
    const plain = { id: 2, name: 'Helmet', supplier_id: 7, sources: [{ id: 201, supplier_id: 9 }], variants: [] };
    const noLines = { id: 3, name: 'Old Stock', supplier_id: 7, variants: [] }; // pre-Part-1 data, no lines yet

    it('matches a supplier present on ANY of the product\'s variant lines', () => {
        expect(productHasSupplier(bulu, 7)).toBe(true);
        expect(productHasSupplier(bulu, 9)).toBe(true);
        expect(productHasSupplier(bulu, 99)).toBe(false);
    });

    it('matches a supplier on the product\'s OWN line (variantless product)', () => {
        expect(productHasSupplier(plain, 9)).toBe(true);
        // the product's stale supplier_id (7) is NOT what sold this lot — a
        // product with real lines must never fall back past them
        expect(productHasSupplier(plain, 7)).toBe(false);
    });

    it('falls back to the single supplier_id for a product with no lines at all', () => {
        expect(productHasSupplier(noLines, 7)).toBe(true);
        expect(productHasSupplier(noLines, 9)).toBe(false);
    });
});

/**
 * Khaled live (Stock's edit sheet AND Barcode Labels, both reported): the
 * page-level filter already decided WHICH products are listed, but a
 * product that matched (because SOME of its sizes/colors belong to that
 * supplier) still showed every one of its OWN variant rows once opened,
 * not just the ones that actually carry that supplier.
 */
describe('variantHasSupplier', () => {
    it('matches a flat supplier_id on a line (Barcode Labels\' shape)', () => {
        const v = { id: 11, sources: [{ id: 101, supplier_id: 7 }] };
        expect(variantHasSupplier(v, 7)).toBe(true);
        expect(variantHasSupplier(v, 9)).toBe(false);
    });

    it('matches a nested supplier object on a line (Stock\'s edit-sheet shape)', () => {
        const v = { id: 11, sources: [{ id: 101, supplier: { id: 7, name: 'Monir Garments' } }] };
        expect(variantHasSupplier(v, 7)).toBe(true);
        expect(variantHasSupplier(v, 9)).toBe(false);
    });

    it('is false for a variant with no matching line at all', () => {
        expect(variantHasSupplier({ id: 11, sources: [] }, 7)).toBe(false);
        expect(variantHasSupplier({ id: 11 }, 7)).toBe(false);
    });
});
