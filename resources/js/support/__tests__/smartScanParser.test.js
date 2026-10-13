import { describe, expect, it } from 'vitest';
import { parseProductText } from '../smartScanParser.js';

describe('parseProductText', () => {
    it('extracts a universal (electronics/grocery) label with MRP, batch, and expiry', () => {
        const text = [
            'ACME Wireless Earbuds Pro',
            'Model: AW-200',
            'MRP: Tk. 2500',
            'Batch No: BT-9921',
            'Mfg Date: 01/03/2025',
            'Exp Date: 01/03/2027',
            'Manufactured by: Acme Electronics Ltd.',
            '8901030875450',
        ].join('\n');

        const result = parseProductText(text);

        expect(result.name).toBe('ACME Wireless Earbuds Pro');
        expect(result.price).toBe('2500');
        expect(result.batch_no).toBe('BT-9921');
        expect(result.expiry_date).toBe('2027-03-01');
        expect(result.mfg_date).toBe('2025-03-01');
        expect(result.company).toBe('Acme Electronics Ltd.');
        expect(result.barcode).toBe('8901030875450');
    });

    it('extracts a pharmacy label with generic name and a month/year expiry', () => {
        const text = [
            'Napa Extra 500mg',
            'Generic: Paracetamol',
            'MRP Tk 1.50 Per Tablet',
            'Batch: N22A',
            'EXP: 06/2026',
            'Marketed by: Beximco Pharmaceuticals',
        ].join('\n');

        const result = parseProductText(text);

        expect(result.name).toBe('Napa Extra 500mg');
        expect(result.generic_name).toBe('Paracetamol');
        expect(result.price).toBe('1.50');
        expect(result.batch_no).toBe('N22A');
        expect(result.expiry_date).toBe('2026-06-01');
        expect(result.company).toBe('Beximco Pharmaceuticals');
    });

    it('never guesses a date from an unlabeled, ambiguous number', () => {
        const text = 'Random Product\nSome Code 12/34/5678\nNo real expiry here';

        const result = parseProductText(text);

        expect(result.expiry_date).toBe('');
        expect(result.mfg_date).toBe('');
    });

    it('returns every field blank rather than guessing, on empty or garbage input', () => {
        expect(parseProductText('')).toMatchObject({ name: '', price: '', batch_no: '', expiry_date: '', barcode: '' });
        expect(parseProductText('!!! ### 123')).toMatchObject({ name: '', price: '' });
    });

    it('skips a labeled line (e.g. "MRP: ...") when looking for the product name', () => {
        const text = 'MRP: 500\nReal Product Name\nExp: 01/01/2030';

        const result = parseProductText(text);

        expect(result.name).toBe('Real Product Name');
    });
});
