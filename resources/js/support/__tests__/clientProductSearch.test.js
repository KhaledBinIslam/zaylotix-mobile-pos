import { describe, it, expect } from 'vitest';
import { searchProducts, productHaystack } from '../clientProductSearch';

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
});
