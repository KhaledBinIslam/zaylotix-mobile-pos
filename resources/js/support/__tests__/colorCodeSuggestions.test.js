import { describe, it, expect } from 'vitest';
import { suggestColorCode } from '../colorCodeSuggestions';

describe('suggestColorCode', () => {
    it('matches the confirmed table for every known color', () => {
        expect(suggestColorCode('SILVER')).toBe('SIL');
        expect(suggestColorCode('KHAKI')).toBe('KHK');
        expect(suggestColorCode('GOLDEN')).toBe('GLD');
        expect(suggestColorCode('WHITE')).toBe('WHT');
        expect(suggestColorCode('BLACK')).toBe('BLK');
        expect(suggestColorCode('BROWN')).toBe('BRN');
        expect(suggestColorCode('COFFEE')).toBe('COF');
        expect(suggestColorCode('MAROON')).toBe('MRN');
        expect(suggestColorCode('GREY')).toBe('GRY');
    });

    it('is case-insensitive', () => {
        expect(suggestColorCode('silver')).toBe('SIL');
    });

    it('falls back to the first three letters for an unlisted color', () => {
        expect(suggestColorCode('Pink')).toBe('PIN');
    });

    it('strips non-letter characters before taking the first three', () => {
        expect(suggestColorCode('Navy Blue')).toBe('NAV');
    });

    it('returns an empty string for an empty input, not an error', () => {
        expect(suggestColorCode('')).toBe('');
        expect(suggestColorCode(undefined)).toBe('');
    });
});
