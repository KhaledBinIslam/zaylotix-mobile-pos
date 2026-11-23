import { describe, it, expect } from 'vitest';
import { applySoldLines } from '@/support/applySoldLines';

const catalog = () => [
    { id: 1, name: 'Plain', stock: 10 },
    { id: 2, name: 'Shoe', stock: 5, variants: [{ id: 21, stock: 2 }, { id: 22, stock: 3 }] },
];

describe('applySoldLines — optimistic POS stock decrement', () => {
    it('a product-only line drops the product stock by qty', () => {
        const rows = applySoldLines(catalog(), [{ product_id: 1, product_variant_id: null, qty: 3 }]);
        expect(rows[0].stock).toBe(7);
        expect(rows[1].stock).toBe(5);
    });

    it('a variant line drops BOTH the variant and its product (products.stock is the variants sum)', () => {
        const rows = applySoldLines(catalog(), [{ product_id: 2, product_variant_id: 21, qty: 1 }]);
        expect(rows[1].stock).toBe(4);
        expect(rows[1].variants[0].stock).toBe(1);
        expect(rows[1].variants[1].stock).toBe(3);
    });

    it('a line for a product not on screen is ignored, others still apply', () => {
        const rows = applySoldLines(catalog(), [
            { product_id: 999, product_variant_id: null, qty: 4 },
            { product_id: 1, product_variant_id: null, qty: 1 },
        ]);
        expect(rows[0].stock).toBe(9);
        expect(rows[1].stock).toBe(5);
    });

    it('selling more than the shown stock goes negative — never clamped to zero', () => {
        const rows = applySoldLines(catalog(), [{ product_id: 2, product_variant_id: 22, qty: 5 }]);
        expect(rows[1].variants[1].stock).toBe(-2);
        expect(rows[1].stock).toBe(0);
    });

    it('several lines on the same product accumulate', () => {
        const rows = applySoldLines(catalog(), [
            { product_id: 2, product_variant_id: 21, qty: 1 },
            { product_id: 2, product_variant_id: 22, qty: 2 },
            { product_id: 2, product_variant_id: null, qty: 1 },
        ]);
        expect(rows[1].stock).toBe(1); // 5 - 1 - 2 - 1
        expect(rows[1].variants[0].stock).toBe(1);
        expect(rows[1].variants[1].stock).toBe(1);
    });

    it('the same object reaching it twice (props.products + searchResults share refs) is counted once', () => {
        const rows = catalog();
        const shared = rows[1];
        applySoldLines([...rows, shared], [{ product_id: 2, product_variant_id: 21, qty: 1 }]);
        expect(shared.stock).toBe(4);
        expect(shared.variants[0].stock).toBe(1);
    });

    it('a variant line on a row without a variants array still drops the product', () => {
        const rows = applySoldLines([{ id: 3, stock: 2 }], [{ product_id: 3, product_variant_id: 77, qty: 1 }]);
        expect(rows[0].stock).toBe(1);
    });

    it('a zero/invalid qty changes nothing', () => {
        const rows = applySoldLines(catalog(), [{ product_id: 1, qty: 0 }, { product_id: 1, qty: 'x' }]);
        expect(rows[0].stock).toBe(10);
    });
});
