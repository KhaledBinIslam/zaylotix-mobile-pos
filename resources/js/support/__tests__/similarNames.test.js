import { describe, it, expect } from 'vitest';
import { levenshtein, findSimilarNames } from '../similarNames';

describe('levenshtein', () => {
    it('is 0 for identical strings', () => {
        expect(levenshtein('sujon', 'sujon')).toBe(0);
    });

    it('counts single-character edits', () => {
        expect(levenshtein('sujon', 'sujno')).toBe(2); // one transposition = 2 single-char edits
        expect(levenshtein('sujon', 'sujn')).toBe(1); // one deletion
        expect(levenshtein('', 'abc')).toBe(3);
    });
});

/**
 * Khaled live: "Sujon" and "Sujon 3rd party" sailed through with zero
 * warning because the server only ever blocked an EXACT match. These pin
 * the soft, client-side similarity check that catches this case before
 * a new supplier is even submitted.
 */
describe('findSimilarNames', () => {
    const existing = ['Bulu', 'Sujon', 'Manik', 'Garments'];

    it('catches one name containing another', () => {
        expect(findSimilarNames('Sujon 3rd party', existing)).toEqual(['Sujon']);
        expect(findSimilarNames('Suj', existing)).toEqual(['Sujon']); // the other direction
    });

    it('catches a typo-sized edit distance', () => {
        expect(findSimilarNames('Sujno', existing)).toEqual(['Sujon']);
    });

    it('is case/whitespace insensitive', () => {
        expect(findSimilarNames('  SUJON 3RD PARTY  ', existing)).toEqual(['Sujon']);
    });

    it('never flags an EXACT match — that is the server hard guard\'s job', () => {
        expect(findSimilarNames('Sujon', existing)).toEqual([]);
        expect(findSimilarNames('sujon', existing)).toEqual([]); // case-insensitive exact too
    });

    it('does not flag genuinely unrelated short names', () => {
        expect(findSimilarNames('Karim', existing)).toEqual([]);
    });

    it('returns empty for a blank name', () => {
        expect(findSimilarNames('', existing)).toEqual([]);
        expect(findSimilarNames('   ', existing)).toEqual([]);
    });
});
