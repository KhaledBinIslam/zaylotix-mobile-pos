import { describe, it, expect } from 'vitest';
import { printedLabelKey } from '../labelPrintKey';

const thermalSettings = { print_mode: 'thermal', show_barcode_number: false, price_mode: 'single' };

/** Two sizes of one colour: same colour-level scan code, different variant barcode. */
const size18 = { name: 'B-20009', variantLabel: 'RED / 18', barcode: 'SMS-B-20009-NSXXZ-RED-18', scanCode: 'SMS-B-20009-NSXXZ-RED', price: 1990, discount_price: null };
const size25 = { ...size18, variantLabel: 'RED / 25', barcode: 'SMS-B-20009-NSXXZ-RED-25' };

describe('printedLabelKey', () => {
    it('collapses two sizes of one colour on a thermal sticker that prints neither size nor number', () => {
        // the live case: eight identical stickers were sent as eight
        // separate Bluetooth jobs because the objects differ by size
        expect(printedLabelKey(size18, thermalSettings, true))
            .toBe(printedLabelKey(size25, thermalSettings, true));
    });

    it('keeps them apart once the barcode number is printed, because the stickers then differ', () => {
        const withNumber = { ...thermalSettings, show_barcode_number: true };
        expect(printedLabelKey(size18, withNumber, true))
            .not.toBe(printedLabelKey(size25, withNumber, true));
    });

    it('keeps them apart on a sheet label, where the size line is printed', () => {
        const sheet = { print_mode: 'sheet', show_variant: true, show_product_name: true, price_mode: 'single' };
        expect(printedLabelKey(size18, sheet, false))
            .not.toBe(printedLabelKey(size25, sheet, false));
    });

    it('ignores the size line on a sheet label when show_variant is off', () => {
        const sheet = { print_mode: 'sheet', show_variant: false, show_product_name: true, show_barcode_number: false, price_mode: 'single' };
        expect(printedLabelKey(size18, sheet, false))
            .toBe(printedLabelKey(size25, sheet, false));
    });

    it('separates two different prices, which always print', () => {
        expect(printedLabelKey(size18, thermalSettings, true))
            .not.toBe(printedLabelKey({ ...size18, price: 2490 }, thermalSettings, true));
    });

    it('ignores the price when no price is printed at all', () => {
        const noPrice = { ...thermalSettings, price_mode: 'none' };
        expect(printedLabelKey(size18, noPrice, true))
            .toBe(printedLabelKey({ ...size18, price: 2490 }, noPrice, true));
    });

    it('separates two colours, whose bars encode different codes', () => {
        const blue = { ...size18, scanCode: 'SMS-B-20009-NSXXZ-BLU' };
        expect(printedLabelKey(size18, thermalSettings, true))
            .not.toBe(printedLabelKey(blue, thermalSettings, true));
    });

    it('falls back to the barcode itself when there is no separate scan code', () => {
        const plain = { barcode: '8901234567890', price: 50 };
        const other = { barcode: '8909999999999', price: 50 };
        expect(printedLabelKey(plain, thermalSettings, true))
            .not.toBe(printedLabelKey(other, thermalSettings, true));
    });

    it('survives an empty label without throwing', () => {
        expect(() => printedLabelKey({}, thermalSettings, true)).not.toThrow();
    });
});
