<?php

namespace App\Support;

/**
 * Root cause (docs/FIX_PLAN.md's "বিলিং দ্রুত করা... সার্চ" ticket, ধাপ ১):
 * য়/ড়/ঢ় each have two valid Unicode byte sequences — one precomposed
 * codepoint, and the canonical base-letter + U+09BC (nukta) decomposition.
 * Unicode marks these three as "composition exclusions", so NFC
 * normalization always collapses BOTH spellings down to the decomposed
 * form (verified against PHP's own intl Normalizer and against
 * resources/js/support/searchNormalize.js — same fixture,
 * tests/fixtures/search_normalize_cases.json, same result). Hand-rolled
 * here instead of calling `Normalizer::normalize()` so this doesn't
 * depend on the `intl` extension being enabled on shared hosting — these
 * three characters are the only actual ambiguity Bengali text search runs
 * into, so a small explicit table covers it without pulling in a full
 * general-purpose Unicode normalizer.
 *
 * This is the STRICT equivalence pass — things that are always, truly the
 * same text regardless of spelling. Looser, typo/phonetic-tolerant
 * matching (ি/ী, শ/ষ/স, Levenshtein, …) is a separate, lower-ranked layer
 * (see SearchMatcher::matchTier()) that never touches this normalized form.
 */
class SearchNormalizer
{
    /** precomposed => canonical decomposed (base consonant + nukta) */
    private const COMPOSE_MAP = [
        "\u{09DF}" => "\u{09AF}\u{09BC}", // য় (yya) -> য + ়
        "\u{09DC}" => "\u{09A1}\u{09BC}", // ড় (rra) -> ড + ়
        "\u{09DD}" => "\u{09A2}\u{09BC}", // ঢ় (rha) -> ঢ + ়
    ];

    /** খণ্ড ত (ৎ, U+09CE) and plain ত (U+09A4) are interchangeable in casual spelling */
    private const KHANDA_TA = "\u{09CE}";

    private const PLAIN_TA = "\u{09A4}";

    /** চন্দ্রবিন্দু (candrabindu, U+0981) is routinely dropped in casual typing — ignored, not matched */
    private const CANDRABINDU = "\u{0981}";

    /** ZWJ, ZWNJ, ZWSP, BOM — invisible characters a keyboard/IME can insert mid-word */
    private const INVISIBLE_PATTERN = '/[\x{200B}\x{200C}\x{200D}\x{FEFF}]/u';

    private const BENGALI_DIGIT_START = 0x09E6; // ০

    public static function normalize(?string $text): string
    {
        if ($text === null || $text === '') {
            return '';
        }

        $s = strtr($text, self::COMPOSE_MAP);
        $s = str_replace(self::KHANDA_TA, self::PLAIN_TA, $s);
        $s = str_replace(self::CANDRABINDU, '', $s);
        $s = preg_replace(self::INVISIBLE_PATTERN, '', $s);
        $s = self::bengaliDigitsToAscii($s);
        $s = preg_replace('/\s+/u', ' ', trim($s));
        $s = mb_strtolower($s, 'UTF-8');

        return $s;
    }

    private static function bengaliDigitsToAscii(string $s): string
    {
        return preg_replace_callback('/[\x{09E6}-\x{09EF}]/u', function ($m) {
            return (string) (mb_ord($m[0], 'UTF-8') - self::BENGALI_DIGIT_START);
        }, $s);
    }
}
