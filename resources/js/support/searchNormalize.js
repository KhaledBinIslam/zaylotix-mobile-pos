/**
 * Mirrors app/Support/SearchNormalizer.php exactly (same reasoning in that
 * file's docblock, same shared fixture at
 * tests/fixtures/search_normalize_cases.json) — য়/ড়/ঢ় are Unicode
 * composition exclusions with two valid spellings each; this collapses
 * both down to one canonical (decomposed) form so search never cares
 * which one a keyboard produced.
 */

const COMPOSE_MAP = [
    ['য়', 'য়'], // য় (yya) -> য + ়
    ['ড়', 'ড়'], // ড় (rra) -> ড + ়
    ['ঢ়', 'ঢ়'], // ঢ় (rha) -> ঢ + ়
];

const KHANDA_TA = 'ৎ';
const PLAIN_TA = 'ত';
const CANDRABINDU = 'ঁ';
const INVISIBLE_PATTERN = /[​‌‍﻿]/g;
const BENGALI_DIGIT_PATTERN = /[০-৯]/g;
const BENGALI_DIGIT_START = 0x09E6;

export function normalizeSearch(text) {
    if (!text) return '';

    let s = String(text);
    for (const [precomposed, decomposed] of COMPOSE_MAP) {
        s = s.split(precomposed).join(decomposed);
    }
    s = s.split(KHANDA_TA).join(PLAIN_TA);
    s = s.split(CANDRABINDU).join('');
    s = s.replace(INVISIBLE_PATTERN, '');
    s = s.replace(BENGALI_DIGIT_PATTERN, (d) => String(d.codePointAt(0) - BENGALI_DIGIT_START));
    s = s.replace(/\s+/g, ' ').trim();
    s = s.toLowerCase();

    return s;
}
