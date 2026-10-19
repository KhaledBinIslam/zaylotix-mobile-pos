import { describe, expect, it } from 'vitest';
import { buildOfflineSaleSnapshot } from '../offlineSaleSnapshot.js';

// Phase 12 (docs/FIX_PLAN.md) — print/WhatsApp were entirely missing for
// an offline-queued (not-yet-synced) sale; this builds a Sale-shaped
// object from the queued payload alone, good enough to feed ReceiptMemo.
describe('buildOfflineSaleSnapshot', () => {
    const basePayload = {
        client_uuid: 'abcd1234-5678-90ab-cdef-123456789012',
        items: [
            { product_name: 'চাল', unit_label: null, variant_label: null, qty: 2, discount: 0, unit_price: 150 },
            { product_name: 'তেল', unit_label: 'বোতল', variant_label: null, qty: 1, discount: 10, unit_price: 80 },
        ],
        discount: 5,
        complimentary: false,
        coupon_code: '',
        payments: [{ method: 'cash', amount: 305 }],
        customer_phone: '',
        customer_name: '',
    };

    it('builds a short, stable placeholder invoice number from the client_uuid', () => {
        const sale = buildOfflineSaleSnapshot(basePayload);
        expect(sale.invoice_no).toBe('OFF-ABCD1234');
        expect(buildOfflineSaleSnapshot(basePayload).invoice_no).toBe(sale.invoice_no); // stable across rebuilds
    });

    it('flags the sale as offline-pending', () => {
        expect(buildOfflineSaleSnapshot(basePayload).isOfflinePending).toBe(true);
    });

    it('computes subtotal and total from the item price snapshots and discounts', () => {
        const sale = buildOfflineSaleSnapshot(basePayload);
        // (150*2) + (80*1 - 10) = 300 + 70 = 370 subtotal; - 5 overall discount = 365
        expect(sale.subtotal).toBe(370);
        expect(sale.discount).toBe(5);
        expect(sale.total).toBe(365);
    });

    it('never lets the overall discount exceed the subtotal', () => {
        const sale = buildOfflineSaleSnapshot({ ...basePayload, discount: 99999 });
        expect(sale.discount).toBe(370);
        expect(sale.total).toBe(0);
    });

    it('a complimentary sale has zero total regardless of the typed discount', () => {
        const sale = buildOfflineSaleSnapshot({ ...basePayload, complimentary: true, discount: 0 });
        expect(sale.total).toBe(0);
        expect(sale.payment_mode).toBe('cash');
    });

    it('derives payment_mode: credit when nothing was tendered', () => {
        const sale = buildOfflineSaleSnapshot({ ...basePayload, payments: [] });
        expect(sale.payment_mode).toBe('credit');
    });

    it('derives payment_mode: split when more than one tender method was used', () => {
        const sale = buildOfflineSaleSnapshot({ ...basePayload, payments: [{ method: 'cash', amount: 200 }, { method: 'bkash', amount: 105 }] });
        expect(sale.payment_mode).toBe('split');
    });

    it('attaches a customer object only when a phone number was given', () => {
        expect(buildOfflineSaleSnapshot(basePayload).customer).toBeNull();
        const withCustomer = buildOfflineSaleSnapshot({ ...basePayload, customer_phone: '01711111111', customer_name: 'Karim' });
        expect(withCustomer.customer).toEqual({ name: 'Karim', phone: '01711111111' });
    });
});
