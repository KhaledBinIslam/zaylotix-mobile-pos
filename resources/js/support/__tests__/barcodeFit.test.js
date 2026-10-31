import { describe, expect, it } from 'vitest';
import { estimateCode128Modules, thermalModuleWidth, THERMAL_BAR_HEIGHT_MM, mmToPx } from '../barcodeFit';

// Phase 41 — the width math behind docs/LABEL_PRINT_PLAN.md's table.
// Usable width on a 38×25 label = 38 − 2×2.5mm quiet zone = 33mm.
describe('estimateCode128Modules', () => {
    it('counts alphanumeric values as Code B (one symbol per char)', () => {
        // 'ZV4821' = 6 chars → 11 + 6×11 + 11 + 13 = 101
        expect(estimateCode128Modules('ZV4821')).toBe(101);
        // the long article-text barcode from the plan table: 17 chars → 222
        expect(estimateCode128Modules('SMS-L-10001-NSXXZ')).toBe(222);
    });

    it('packs all-digit values as Code C (two digits per symbol)', () => {
        // 13 digits → ceil(13/2)+1 = 8 symbols → 11 + 88 + 11 + 13 = 123
        expect(estimateCode128Modules('2000000012345')).toBe(123);
    });

    it('handles empty input', () => {
        expect(estimateCode128Modules('')).toBe(0);
    });
});

describe('thermalModuleWidth', () => {
    const USABLE = 33;

    it('variant scan codes fit at the preferred 2 dots per module', () => {
        const r = thermalModuleWidth('ZV4821', USABLE);
        expect(r.twoDot).toBe(true);
        expect(r.moduleMm).toBe(0.25);
        expect(r.fits).toBe(true);
        // even the largest plausible id stays two-dot
        expect(thermalModuleWidth('ZV999999', USABLE).twoDot).toBe(true);
    });

    it('a long article-text barcode falls back to 1 dot with fits=true', () => {
        // 222 modules × 0.25 = 55.5mm (no), × 0.125 = 27.75mm (yes)
        const r = thermalModuleWidth('SMS-L-10001-NSXXZ', USABLE);
        expect(r.twoDot).toBe(false);
        expect(r.moduleMm).toBe(0.125);
        expect(r.fits).toBe(true);
    });

    it('an absurdly long value reports fits=false so the label can warn', () => {
        const r = thermalModuleWidth('SMS-L-10001-NSXXZ-SIL-36-EXTRA-LONG-SUFFIX', USABLE);
        expect(r.fits).toBe(false);
    });

    it('mm to css px uses the 96dpi definition', () => {
        expect(mmToPx(25.4)).toBeCloseTo(96, 5);
        expect(mmToPx(0.25)).toBeCloseTo(0.94488, 4);
    });
});

// A thermal sticker drops the product-name and size/colour lines (the
// bars' own printed text already spells the article out), so their height
// goes to the bars instead of leaving the bottom third blank.
describe('THERMAL_BAR_HEIGHT_MM', () => {
    const USABLE_MM = 33;

    it('is the 9mm the three-block thermal layout is built around', () => {
        expect(THERMAL_BAR_HEIGHT_MM).toBe(9);
    });

    it('still leaves a 25mm label room for the shop name, bar text and price', () => {
        // 25mm card − 2px×2 padding ≈ 90.5 css px of usable height.
        // shop name ~12px + 3px, bars + their 8px text + 3px, price ~12px
        const px = (mm) => mm * (96 / 25.4);
        const used = 15 + (px(THERMAL_BAR_HEIGHT_MM) + 8 + 2 + 3) + 12;
        expect(used).toBeLessThan(90.5); // nothing is ever clipped
    });

    it('does not change how wide a module may be — height and width are independent', () => {
        // Width is decided by what the bars ENCODE (the short scanCode),
        // never by the long article text printed under them — which on its
        // own would overflow the label, and is exactly why the two were
        // decoupled (see BarcodeLabelCard's render docblock).
        expect(thermalModuleWidth('ZV4821', USABLE_MM).fits).toBe(true);
        expect(thermalModuleWidth('SMS-B-20001-NSORZ-BRN', USABLE_MM).fits).toBe(false);
    });
});
