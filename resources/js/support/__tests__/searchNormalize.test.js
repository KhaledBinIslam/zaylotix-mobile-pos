import { describe, it, expect } from 'vitest';
import { readFileSync } from 'fs';
import { fileURLToPath } from 'url';
import { dirname, join } from 'path';
import { normalizeSearch } from '../searchNormalize';

const __dirname = dirname(fileURLToPath(import.meta.url));
const fixturePath = join(__dirname, '../../../../tests/fixtures/search_normalize_cases.json');
const cases = JSON.parse(readFileSync(fixturePath, 'utf8'));

describe('normalizeSearch (shared fixture with SearchNormalizerTest.php)', () => {
    it.each(cases)('$name', ({ input, expected }) => {
        expect(normalizeSearch(input)).toBe(expected);
    });

    it('normalizing an already-normalized string is a no-op (idempotent)', () => {
        const s = normalizeSearch('সয়াসস ২০০ মিলি  Soy Sauce');
        expect(normalizeSearch(s)).toBe(s);
    });
});
