import { describe, it, expect } from 'vitest';
import { printableRowsOf } from '../labelSelection';

describe('printableRowsOf', () => {
    it('picks every size, because a sticker goes on a pair of a given size', () => {
        // Khaled: "stricker gula size colr wise print korte hobe". Each
        // size carries its own scan code (ZV<id>), so the sizes print as
        // distinct stickers — the colour row prints ONE for the lot, and
        // is left for whoever taps it deliberately.
        const product = {
            id: 1,
            color_barcodes: [{ id: 7, color: 'WHITE', stock: 18 }],
            variants: [
                { id: 100, barcode: 'A-WHT-16', stock: 2 },
                { id: 101, barcode: 'A-WHT-17', stock: 3 },
            ],
        };

        expect(printableRowsOf(product)).toEqual([
            { key: 'v:100', copies: 2 },
            { key: 'v:101', copies: 3 },
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

    it('still offers one sticker for a size that is out of stock', () => {
        // a reprint for a shoe already on the shelf must stay possible
        const product = { id: 1, variants: [{ id: 100, barcode: 'A-16', stock: 0 }] };

        expect(printableRowsOf(product)).toEqual([{ key: 'v:100', copies: 1 }]);
    });

    it('copes with a product carrying nothing at all', () => {
        expect(printableRowsOf({})).toEqual([]);
        expect(printableRowsOf(null)).toEqual([]);
    });
});
