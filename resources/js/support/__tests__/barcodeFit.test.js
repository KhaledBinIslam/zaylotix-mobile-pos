import { describe, expect, it } from 'vitest';
import { estimateCode128Modules, thermalModuleWidth, mmToPx } from '../barcodeFit';

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
