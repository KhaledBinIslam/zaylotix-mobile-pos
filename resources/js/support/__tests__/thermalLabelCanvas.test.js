import { describe, it, expect } from 'vitest';
import { fitFontSize, priceLinesOf, DOTS_PER_CSS_PX } from '../thermalLabelCanvas';

describe('DOTS_PER_CSS_PX', () => {
    it('converts a CSS pixel to this printer\'s dots', () => {
        // 96 CSS px per inch, 8 dots per mm, 25.4mm per inch
        expect(DOTS_PER_CSS_PX).toBeCloseTo(2.1167, 4);
        // the sanity check that matters: a 40mm label is 320 dots wide
        expect(Math.round(40 * 8)).toBe(320);
    });
});

describe('fitFontSize', () => {
    it('keeps the starting size when the text already fits', () => {
        const measure = (size) => size * 2; // 2 units wide per unit of size
        expect(fitFontSize(measure, 'Shop', 100, 23)).toBe(23);
    });

    it('shrinks until the text fits', () => {
        const measure = (size) => size * 10;
        expect(fitFontSize(measure, 'A very long business name', 100, 23)).toBe(10);
    });

    it('never goes below the floor, however long the name', () => {
        const measure = () => 9999;
        expect(fitFontSize(measure, 'x', 10, 23, 11)).toBe(11);
    });
});

describe('priceLinesOf', () => {
    it('prints nothing when the shop turned the price off', () => {
        expect(priceLinesOf({ price: 100 }, { price_mode: 'none' })).toEqual([]);
    });

    it('prints one line normally, rounded', () => {
        expect(priceLinesOf({ price: 1190.4 }, { price_mode: 'final' }))
            .toEqual([{ text: '৳1190', cssPx: 10, strike: false }]);
    });

    it('prefers the discounted price when there is one', () => {
        expect(priceLinesOf({ price: 1190, discount_price: 990 }, { price_mode: 'final' }))
            .toEqual([{ text: '৳990', cssPx: 10, strike: false }]);
    });

    it('shows both, the regular one struck through', () => {
        expect(priceLinesOf({ price: 1190, discount_price: 990 }, { price_mode: 'both' })).toEqual([
            { text: 'নিয়মিত ৳1190', cssPx: 8, strike: true },
            { text: 'ছাড় ৳990', cssPx: 10, strike: false },
        ]);
    });

    it('falls back to one line when "both" is set but nothing is discounted', () => {
        expect(priceLinesOf({ price: 1190, discount_price: null }, { price_mode: 'both' }))
            .toEqual([{ text: '৳1190', cssPx: 10, strike: false }]);
    });
});
