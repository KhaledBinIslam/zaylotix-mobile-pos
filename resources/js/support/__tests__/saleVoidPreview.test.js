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
