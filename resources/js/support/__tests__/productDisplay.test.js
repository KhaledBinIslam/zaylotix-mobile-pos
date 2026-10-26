import { describe, it, expect } from 'vitest';
import { variantPriceBounds, variantStockTotal, colorGroups } from '../productDisplay';

describe('variantPriceBounds', () => {
    it('returns the product price twice when there are no variants', () => {
        expect(variantPriceBounds({ price: 500, variants: [] })).toEqual({ min: 500, max: 500 });
    });

    it('prefers discount_price over price when there are no variants', () => {
        expect(variantPriceBounds({ price: 500, discount_price: 400, variants: [] })).toEqual({ min: 400, max: 400 });
    });

    it('returns min === max when every variant costs the same', () => {
        const product = { price: 500, variants: [{ price: 500 }, { price: 500 }] };
        expect(variantPriceBounds(product)).toEqual({ min: 500, max: 500 });
    });

    it('returns the real min/max when variants differ in price', () => {
        const product = { price: 1200, variants: [{ price: 1200 }, { price: 1800 }, { price: 1500 }] };
        expect(variantPriceBounds(product)).toEqual({ min: 1200, max: 1800 });
    });

    it('falls back to the base product price for a variant with no own price', () => {
        const product = { price: 1200, variants: [{ price: null }, { price: 1800 }] };
        expect(variantPriceBounds(product)).toEqual({ min: 1200, max: 1800 });
    });
});

describe('variantStockTotal', () => {
    it('returns 0 for a product with no variants', () => {
        expect(variantStockTotal({ variants: [] })).toBe(0);
    });

    it('sums every variant stock, including a zero-stock one', () => {
        const product = { variants: [{ stock: 10 }, { stock: 0 }, { stock: 5 }] };
        expect(variantStockTotal(product)).toBe(15);
    });

    it('treats a null/undefined variant stock as 0', () => {
        const product = { variants: [{ stock: 10 }, { stock: null }, { stock: undefined }] };
        expect(variantStockTotal(product)).toBe(10);
    });
});

describe('colorGroups', () => {
    it('returns an empty array for a product with no variants', () => {
        expect(colorGroups({ variants: [] })).toEqual([]);
    });

    it('groups variants by color, preserving first-seen order', () => {
        const product = {
            variants: [
                { id: 1, color: 'SILVER', size: '36' },
                { id: 2, color: 'BLACK', size: '36' },
                { id: 3, color: 'SILVER', size: '37' },
            ],
        };
        const groups = colorGroups(product);
        expect(groups.map((g) => g.color)).toEqual(['SILVER', 'BLACK']);
        expect(groups[0].variants).toHaveLength(2);
        expect(groups[1].variants).toHaveLength(1);
    });

    it('uses the first variant with a photo as the groups photo', () => {
        const product = {
            variants: [
                { id: 1, color: 'SILVER', photo_url: null },
                { id: 2, color: 'SILVER', photo_url: 'https://x/silver.jpg' },
            ],
        };
        expect(colorGroups(product)[0].photoUrl).toBe('https://x/silver.jpg');
    });

    it('a group with no photo on any variant has a null photoUrl, not an error', () => {
        const product = { variants: [{ id: 1, color: 'SILVER', photo_url: null }] };
        expect(colorGroups(product)[0].photoUrl).toBeNull();
    });

    it('a variant with no color groups under a single null-color bucket', () => {
        const product = { variants: [{ id: 1, color: null, size: '36' }, { id: 2, color: null, size: '37' }] };
        const groups = colorGroups(product);
        expect(groups).toHaveLength(1);
        expect(groups[0].variants).toHaveLength(2);
    });
});
