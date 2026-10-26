import { describe, it, expect } from 'vitest';
import { variantPriceBounds, variantStockTotal } from '../productDisplay';

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
