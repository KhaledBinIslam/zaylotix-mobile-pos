import { describe, expect, it } from 'vitest';
import { translations } from '../i18n';

/**
 * The dictionary is one huge JS object literal. A single stray apostrophe in an English string
 * ("each product's profit") broke the production build while every other test stayed green, because no test
 * loaded the file. Importing it here makes that a test failure, and every key must carry both languages.
 */
describe('i18n dictionary', () => {
    it('loads, and every key has a non-empty Bengali and English text', () => {
        const keys = Object.keys(translations);
        expect(keys.length).toBeGreaterThan(1000);
        const bad = keys.filter((k) => !String(translations[k]?.bn || '').trim() || !String(translations[k]?.en || '').trim());
        expect(bad).toEqual([]);
    });

    it('the new profit-split keys exist', () => {
        for (const k of ['accProfit.title', 'accProfit.offline', 'accProfit.online', 'accProfit.total', 'accProfit.byProduct']) {
            expect(translations[k], k).toBeTruthy();
        }
    });
});
