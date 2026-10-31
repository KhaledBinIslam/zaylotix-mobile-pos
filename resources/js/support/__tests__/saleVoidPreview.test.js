import { describe, expect, it } from 'vitest';
import { buildVoidPreview } from '../saleVoidPreview';

// Phase 39 — display-only mirror of app/Support/SaleReversal's math for the
// void confirmation modal; these fixtures match SaleVoidCompletenessTest's
// server-side scenarios so the preview and the real reversal can't drift.
describe('buildVoidPreview', () => {
    const items = [
        { product_name: 'Rice', variant_label: null, unit_label: null, qty: 2 },
        { product_name: 'Shoe', variant_label: '36, KHAKI', unit_label: null, qty: 1 },
    ];

    it('splits tenders into cash and bank buckets, skipping cod', () => {
        const preview = buildVoidPreview({
            items,
            total: 300,
            payments: [
                { method: 'cash', amount: 100 },
                { method: 'bkash', amount: 120 },
                { method: 'cod', amount: 50 },
            ],
        });
        expect(preview.cashBack).toBe(100);
        expect(preview.bankBack).toBe(120);
        expect(preview.items).toHaveLength(2);
        expect(preview.items[1].variantLabel).toBe('36, KHAKI');
    });

    it('legacy sale with no payment rows reverses by payment_mode', () => {
        expect(buildVoidPreview({ items, total: 300, payments: [], payment_mode: 'cash' }).cashBack).toBe(300);
        expect(buildVoidPreview({ items, total: 300, payments: [], payment_mode: 'bkash' }).bankBack).toBe(300);
        expect(buildVoidPreview({ items, total: 300, payments: [], payment_mode: 'credit' }).dueReduction).toBe(300);
    });

    it('collected due becomes advance credit, only the outstanding part reduces due', () => {
        // 200tk sale, 50 tendered -> 150 due; 100 of it already collected
        const preview = buildVoidPreview(
            { items, total: 200, payments: [{ method: 'cash', amount: 50 }] },
            100
        );
        expect(preview.advanceCredit).toBe(100);
        expect(preview.dueReduction).toBe(50);
        expect(preview.cashBack).toBe(50);
    });

    it('advance credit is capped at the due remainder', () => {
        const preview = buildVoidPreview(
            { items, total: 200, payments: [{ method: 'cash', amount: 150 }] },
            500 // over-collected/general collections can never exceed what this bill charged
        );
        expect(preview.advanceCredit).toBe(50);
        expect(preview.dueReduction).toBe(0);
    });

    it('points delta is redeemed minus earned', () => {
        const preview = buildVoidPreview({
            items, total: 90,
            payments: [{ method: 'cash', amount: 90 }],
            points_redeemed: 5, points_earned: 1,
        });
        expect(preview.pointsDelta).toBe(4); // customer gets 5 back, loses the 1 earned
    });

    it('fully paid sale has no due movement at all', () => {
        const preview = buildVoidPreview({ items, total: 100, payments: [{ method: 'cash', amount: 100 }] });
        expect(preview.dueReduction).toBe(0);
        expect(preview.advanceCredit).toBe(0);
    });
});

// A bill that was partly refunded can now be voided (SaleReversal nets the
// two instead of double-crediting) — so the preview has to show the
// REMAINDER the void will move, not the full invoice.
describe('buildVoidPreview with a return already processed', () => {
    const items = [
        { id: 11, product_name: 'Rice', variant_label: null, unit_label: null, qty: 2 },
        { id: 12, product_name: 'Shoe', variant_label: '36, KHAKI', unit_label: null, qty: 1 },
    ];
    const sale = { items, total: 300, payments: [{ method: 'cash', amount: 300 }] };

    it('lists only the units not already returned', () => {
        const preview = buildVoidPreview(sale, 0, { refund: 100, qtyByItem: { 11: 1 } });
        expect(preview.items).toEqual([
            { name: 'Rice', variantLabel: null, qty: 1 },
            { name: 'Shoe', variantLabel: '36, KHAKI', qty: 1 },
        ]);
    });

    it('drops a line that came back in full instead of showing qty 0', () => {
        const preview = buildVoidPreview(sale, 0, { refund: 100, qtyByItem: { 12: 1 } });
        expect(preview.items.map((l) => l.name)).toEqual(['Rice']);
    });

    it('cash back is the tender minus the refund already paid out', () => {
        const preview = buildVoidPreview(sale, 0, { refund: 100, qtyByItem: { 11: 1 } });
        expect(preview.cashBack).toBe(200); // not the full 300
        expect(preview.returnedRefund).toBe(100);
    });

    it('a fully refunded bill moves no cash and lists no stock', () => {
        const preview = buildVoidPreview(sale, 0, { refund: 300, qtyByItem: { 11: 2, 12: 1 } });
        expect(preview.cashBack).toBe(0);
        expect(preview.items).toEqual([]);
    });

    it('a refund against a credit sale eats the due instead of the drawer', () => {
        // nothing tendered, so the whole 300 was due; a 100 refund wrote off
        // 100 of it, leaving 200 for the void to clear
        const preview = buildVoidPreview(
            { items, total: 300, payments: [], payment_mode: 'credit' },
            0,
            { refund: 100, qtyByItem: { 11: 1 } },
        );
        expect(preview.dueReduction).toBe(200);
        expect(preview.cashBack).toBe(0);
    });

    it('never promises more back than was taken, even on a bad refund figure', () => {
        const preview = buildVoidPreview(sale, 0, { refund: 9999, qtyByItem: {} });
        expect(preview.cashBack).toBe(0); // clamped, never negative
    });

    it('no return at all leaves every number exactly as before', () => {
        const withEmpty = buildVoidPreview(sale, 0, {});
        const without = buildVoidPreview(sale, 0);
        expect(withEmpty).toEqual(without);
        expect(without.cashBack).toBe(300);
        expect(without.items).toHaveLength(2);
    });
});
