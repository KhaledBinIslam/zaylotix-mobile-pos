import { describe, it, expect } from 'vitest';
import { readFileSync } from 'fs';
import { fileURLToPath } from 'url';
import { dirname, join } from 'path';
import { searchMatchTier, bestMatchTier, levenshtein, MATCH_TIER } from '../searchMatch';

const __dirname = dirname(fileURLToPath(import.meta.url));
const fixturePath = join(__dirname, '../../../../tests/fixtures/search_match_cases.json');
const cases = JSON.parse(readFileSync(fixturePath, 'utf8'));

describe('searchMatchTier (shared fixture with SearchMatcherTest.php)', () => {
    it.each(cases)('$name', ({ query, text, expectedTier }) => {
        expect(searchMatchTier(query, text)).toBe(expectedTier);
    });
});

describe('levenshtein', () => {
    it('is 0 for identical strings', () => {
        expect(levenshtein('abc', 'abc')).toBe(0);
    });

    it('counts a single insertion as distance 1', () => {
        expect(levenshtein('টমটো', 'টমেটো')).toBe(1);
    });

    it('handles empty strings', () => {
        expect(levenshtein('', 'abc')).toBe(3);
        expect(levenshtein('abc', '')).toBe(3);
    });
});

describe('bestMatchTier', () => {
    it('returns BARCODE regardless of text fields when barcodeExact is set', () => {
        expect(bestMatchTier('anything', ['completely unrelated'], { barcodeExact: true })).toBe(MATCH_TIER.BARCODE);
    });

    it('returns the best (lowest) tier across multiple fields', () => {
        // substring in the first field, prefix in the second — prefix wins
        const tier = bestMatchTier('soy', ['a soy product', 'soy sauce']);
        expect(tier).toBe(MATCH_TIER.PREFIX);
    });

    it('returns null when no field matches', () => {
        expect(bestMatchTier('xyz', ['abc', 'def'])).toBeNull();
    });
});
