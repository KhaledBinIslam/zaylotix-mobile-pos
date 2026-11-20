import { describe, it, expect } from 'vitest';
import { buildReceiptWhatsAppText, whatsAppUrlFor } from '@/support/receiptMessage';

/**
 * Khaled's explicit ask: the memo WhatsApp text is ALWAYS English,
 * regardless of the shop's own UI language, and the shop name always
 * uses the shop's English name (falling back to the Bengali one only
 * when name_en is empty, since it's a nullable column).
 */
describe('buildReceiptWhatsAppText', () => {
    const baseSale = {
        invoice_no: 'INV-1', date: '2026-11-17', time: '12:00:00',
        subtotal: 100, discount: 0, service_charge: 0, vat: 0, total: 100,
        payment_mode: 'cash', items: [{ product_name: 'Rice', qty: 1, price: 100, discount: 0 }],
        payments: [{ amount: 100 }],
    };

    it('uses the shop\'s English name when present', () => {
        const text = buildReceiptWhatsAppText({ name: 'বেবি পার্ক', name_en: 'Baby Park' }, baseSale);
        expect(text).toContain('*Baby Park*');
        expect(text).not.toContain('বেবি পার্ক');
    });

    it('falls back to the Bengali name when name_en is empty', () => {
        const text = buildReceiptWhatsAppText({ name: 'বেবি পার্ক', name_en: '' }, baseSale);
        expect(text).toContain('*বেবি পার্ক*');
    });

    it('never includes Bengali labels in the memo text, even when called from a Bengali-locale page', () => {
        const text = buildReceiptWhatsAppText({ name_en: 'Shop' }, { ...baseSale, discount: 10, service_charge: 5, vat: 2 });
        expect(text).toContain('Subtotal:');
        expect(text).toContain('Discount:');
        expect(text).toContain('Service Charge:');
        expect(text).toContain('*Total:');
        expect(text).toContain('Customer:');
        // no Bengali-script characters at all, except the ৳ (Taka) currency
        // sign itself (U+09F3) — it lives inside the Bengali Unicode block
        // but is just the currency symbol, not Bengali text
        expect(text.replace(/৳/g, '')).not.toMatch(/[ঀ-৿]/);
    });

    it('shows a due balance line when the sale was not fully tendered', () => {
        const text = buildReceiptWhatsAppText({ name_en: 'Shop' }, { ...baseSale, total: 150, payments: [{ amount: 100 }] });
        expect(text).toContain('Due balance: ৳50');
    });
});

describe('whatsAppUrlFor', () => {
    it('builds a wa.me link with the customer phone number', () => {
        const url = whatsAppUrlFor({ customer: { phone: '01711111111' } }, 'hello');
        expect(url).toBe('https://wa.me/8801711111111?text=hello');
    });
});
