import { describe, expect, it } from 'vitest';
import { encodeCost, COST_SUFFIX } from '../costCode';

// Mirrors tests/Unit/ArticleBarcodeTest.php — the preview must agree with
// what the server will actually save, or the owner sees one barcode while
// filling the form and a different one on the sticker.
describe('encodeCost', () => {
    const DEFAULTS = { 0: 'X', 1: 'N', 3: 'S', 4: 'I', 5: 'R', 6: 'J' };
    const FULL = { ...DEFAULTS, 2: 'A', 7: 'T', 8: 'E', 9: 'P' };

    it('matches the server on Khaled s real costs', () => {
        expect(encodeCost(DEFAULTS, 1300)).toBe('NSXXZ');
        expect(encodeCost(DEFAULTS, 1350)).toBe('NSRXZ');
        expect(encodeCost(DEFAULTS, 1450)).toBe('NIRXZ');
    });

    it('always terminates with Z', () => {
        for (const cost of [0, 1, 60, 1300, 100000]) {
            expect(encodeCost(DEFAULTS, cost).endsWith(COST_SUFFIX)).toBe(true);
        }
    });

    it('returns null when a digit has no letter yet, never a partial code', () => {
        // 9 is unmapped in the defaults — this is the live case that blocked
        // cost 900, and the preview must show "?" rather than "XXZ"
        expect(encodeCost(DEFAULTS, 900)).toBeNull();
        expect(encodeCost(DEFAULTS, 1290)).toBeNull();
        expect(encodeCost(FULL, 900)).toBe('PXXZ');
    });

    it('treats an empty cost as nothing to encode', () => {
        expect(encodeCost(DEFAULTS, '')).toBeNull();
        expect(encodeCost(DEFAULTS, null)).toBeNull();
        expect(encodeCost(DEFAULTS, undefined)).toBeNull();
    });

    it('survives a missing map entirely instead of throwing', () => {
        expect(encodeCost(undefined, 1300)).toBeNull();
        expect(encodeCost({}, 1300)).toBeNull();
    });

    it('encodes the whole-number part of a typed cost', () => {
        // the field is numeric, but a stray decimal must not produce a
        // code containing a dot
        expect(encodeCost(DEFAULTS, 1300.75)).toBe('NSXXZ');
        expect(encodeCost(DEFAULTS, '1300')).toBe('NSXXZ');
    });
});
