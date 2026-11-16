import { describe, it, expect } from 'vitest';
import { matchRentableProduct, matchRentalReturnItem } from '@/support/rentalScan';

/**
 * Khaled's explicit ask (2026-11-16): prove the scan-matching logic
 * actually works before claiming it does — this is the real code both
 * Rentals/Index.vue (pick a product to rent) and Show.vue (pick a
 * return) call, not a parallel copy that could drift from what's
 * actually running.
 */
describe('matchRentableProduct — "নতুন রেন্টাল" scan-to-add', () => {
    const products = [
        { id: 1, name: 'Sherwani Plain', barcode: 'STD-SHR-10002', rent_price: 1500, variants: [] },
        {
            id: 2, name: 'Pagri', barcode: 'STD-PGR-10001', rent_price: 300,
            variants: [
                { id: 21, label: 'Free Golden', barcode: 'STD-PGR-10001-GLD-FREE' },
                { id: 22, label: 'Free Red', barcode: 'STD-PGR-10001-RED-FREE' },
            ],
        },
    ];

    it('matches a variant barcode and returns its product + variant id', () => {
        const result = matchRentableProduct(products, 'STD-PGR-10001-RED-FREE');
        expect(result).toEqual({ status: 'variant', productId: 2, variantId: 22 });
    });

    it('matches a variant-less product directly (no size/color selection needed)', () => {
        const result = matchRentableProduct(products, 'STD-SHR-10002');
        expect(result).toEqual({ status: 'product', productId: 1 });
    });

    it('scanning the PLAIN product barcode of a product that HAS variants asks for size/color instead of guessing one', () => {
        const result = matchRentableProduct(products, 'STD-PGR-10001');
        expect(result).toEqual({ status: 'needs_variant', productId: 2 });
    });

    it('an unknown barcode (not in the rentable catalog) is reported as not found', () => {
        const result = matchRentableProduct(products, 'SOME-RANDOM-CODE');
        expect(result).toEqual({ status: 'not_found' });
    });

    it('a barcode belonging to a product that exists but is sell_only (never in this rentable list at all) is not found', () => {
        // the backend already filters rentableProducts() to rent_only/both —
        // this proves the frontend match correctly finds nothing for a
        // barcode that was simply never included in that list
        const result = matchRentableProduct(products, 'SELL-ONLY-PRODUCT-BARCODE');
        expect(result.status).toBe('not_found');
    });
});

describe('matchRentalReturnItem — scan-to-select-a-return', () => {
    const rawItems = [
        { id: 101, product_name: 'Pagri', variant_label: null, barcode: 'STD-PGR-10001', outstanding_qty: 1 },
        { id: 102, product_name: 'Mala', variant_label: null, barcode: 'STD-MLA-10001', outstanding_qty: 0 },
        { id: 103, product_name: 'Sherwani', variant_label: 'M Golden', barcode: 'STD-SHR-10001-GLD-M', outstanding_qty: 2 },
    ];

    it('matches an item that still has something outstanding to return', () => {
        const result = matchRentalReturnItem(rawItems, 'STD-PGR-10001');
        expect(result.status).toBe('ok');
        expect(result.item.id).toBe(101);
    });

    it('matches a variant-level barcode on a specific rental item', () => {
        const result = matchRentalReturnItem(rawItems, 'STD-SHR-10001-GLD-M');
        expect(result.status).toBe('ok');
        expect(result.item.id).toBe(103);
    });

    it('an item already fully returned (outstanding_qty 0) is reported distinctly, not silently matched', () => {
        const result = matchRentalReturnItem(rawItems, 'STD-MLA-10001');
        expect(result.status).toBe('fully_returned');
        expect(result.item.id).toBe(102);
    });

    it('a barcode that belongs to a DIFFERENT rental entirely (not in this one\'s rawItems) is not found', () => {
        const result = matchRentalReturnItem(rawItems, 'STD-SOME-OTHER-RENTALS-PRODUCT');
        expect(result.status).toBe('not_found');
    });
});
