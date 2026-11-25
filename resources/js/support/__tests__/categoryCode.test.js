import { describe, expect, it } from 'vitest';
import { suggestCategoryCode } from '../categoryCode';

describe('suggestCategoryCode', () => {
    it('uses the initials of a multi-word English name', () => {
        expect(suggestCategoryCode({ name_en: "Men's Shoes" }, [])).toBe('MS');
        expect(suggestCategoryCode({ name_en: 'Boiler Suit' }, [])).toBe('BS');
        expect(suggestCategoryCode({ name_en: 'Merchant Navy T-Shirt' }, [])).toBe('MNTS');
    });

    it('uses the first letters of a single word', () => {
        expect(suggestCategoryCode({ name_en: 'Sandals' }, [])).toBe('SAN');
        expect(suggestCategoryCode({ name_en: 'Helmet' }, [])).toBe('HEL');
    });

    it('falls back to the plain name when there is no English name', () => {
        expect(suggestCategoryCode({ name: 'Slippers' }, [])).toBe('SLI');
    });

    it('never produces Bengali or symbols — a Bengali-only name gets a safe letter', () => {
        const code = suggestCategoryCode({ name: 'স্যান্ডেল', name_en: '' }, []);
        expect(code).toMatch(/^[A-Z0-9]{1,5}$/);
    });

    it('prefers a code no other category already uses', () => {
        expect(suggestCategoryCode({ name_en: 'Sandals' }, ['SAN'])).toBe('SAN2');
        expect(suggestCategoryCode({ name_en: 'Sandals' }, ['san', 'SAN2'])).toBe('SAN3');
    });

    it('stays within five characters even when it has to number itself', () => {
        const code = suggestCategoryCode({ name_en: 'Merchant Navy T-Shirt' }, ['MNTS']);
        expect(code.length).toBeLessThanOrEqual(5);
        expect(code).not.toBe('MNTS');
    });
});
