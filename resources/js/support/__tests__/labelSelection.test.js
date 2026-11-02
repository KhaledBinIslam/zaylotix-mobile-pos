import { describe, it, expect } from 'vitest';
import { printableRowsOf } from '../labelSelection';

describe('printableRowsOf', () => {
    it('picks the colour rows when the sticker is one per colour', () => {
        // "include size in barcode" off: every size of a colour prints
        // the identical sticker, so the colour row is what to tick
        const product = {
            id: 1,
            color_barcodes: [{ id: 7, color: 'WHITE', stock: 18 }, { id: 8, color: 'PINK', stock: 14 }],
            variants: [{ id: 100, barcode: 'A-WHT-16', stock: 2 }, { id: 101, barcode: 'A-WHT-17', stock: 3 }],
        };

        expect(printableRowsOf(product)).toEqual([
            { key: 'c:7', copies: 18 },
            { key: 'c:8', copies: 14 },
        ]);
    });

    it('falls back to the sizes when there are no colour stickers', () => {
        const product = {
            id: 1,
            color_barcodes: [],
            variants: [{ id: 100, barcode: 'A-16', stock: 4 }, { id: 101, barcode: 'A-17', stock: 1 }],
        };

        expect(printableRowsOf(product)).toEqual([
            { key: 'v:100', copies: 4 },
            { key: 'v:101', copies: 1 },
        ]);
    });

    it('leaves out a size with no barcode, which has nothing to print', () => {
        const product = {
            id: 1,
            variants: [{ id: 100, barcode: null, stock: 4 }, { id: 101, barcode: 'A-17', stock: 2 }],
        };

        expect(printableRowsOf(product)).toEqual([{ key: 'v:101', copies: 2 }]);
    });

    it('uses the product itself when it has no sizes at all', () => {
        expect(printableRowsOf({ id: 5, barcode: 'PLAIN', stock: 9 })).toEqual([{ key: 'p:5', copies: 9 }]);
        expect(printableRowsOf({ id: 5, barcode: null, stock: 9 })).toEqual([]);
    });

    it('still offers one sticker for a row that is out of stock', () => {
        // a reprint for a shoe already on the shelf must stay possible
        const product = { id: 1, color_barcodes: [{ id: 7, color: 'WHITE', stock: 0 }] };

        expect(printableRowsOf(product)).toEqual([{ key: 'c:7', copies: 1 }]);
    });

    it('copes with a product carrying nothing at all', () => {
        expect(printableRowsOf({})).toEqual([]);
        expect(printableRowsOf(null)).toEqual([]);
    });
});
