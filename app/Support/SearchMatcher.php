<?php

namespace App\Support;

/**
 * Mirrors resources/js/support/searchMatch.js exactly (shared fixture at
 * tests/fixtures/search_match_cases.json). Operates on ALREADY-normalized
 * text (see SearchNormalizer::normalize()) — never re-normalizes here.
 *
 * Layered ranking (docs/FIX_PLAN.md's ধাপ ২.খ): lower tier number = better
 * match. Tier 1 (barcode) is decided by the caller via `barcodeExact`,
 * since only the caller knows which field is actually a barcode.
 */
class SearchMatcher
{
    public const TIER_BARCODE = 1;

    public const TIER_PREFIX = 2;

    public const TIER_WORD_PREFIX = 3;

    public const TIER_SUBSTRING = 4;

    public const TIER_TYPO_TOLERANT = 5;

    public const TIER_PHONETIC = 6;

    /** `query` and `text` must already be SearchNormalizer::normalize()-ed. */
    public static function matchTier(string $query, string $text): ?int
    {
        if ($query === '' || $text === '') {
            return null;
        }
        if (str_starts_with($text, $query)) {
            return self::TIER_PREFIX;
        }

        $queryWords = self::splitWords($query);
        $textWords = self::splitWords($text);

        if ($queryWords !== [] && self::everyWordPrefixesSome($queryWords, $textWords)) {
            return self::TIER_WORD_PREFIX;
        }
        if (str_contains($text, $query)) {
            return self::TIER_SUBSTRING;
        }
        if (self::fuzzyWordsMatch($queryWords, $textWords)) {
            return self::TIER_TYPO_TOLERANT;
        }
        if (self::phoneticWordsMatch($queryWords, $textWords)) {
            return self::TIER_PHONETIC;
        }

        return null;
    }

    /**
     * Convenience for a product with several searchable fields (name,
     * name_en, generic_name, keywords, …) plus a separate exact-barcode
     * check — the single best (lowest) tier across all of them, or null.
     *
     * @param  string[]  $fields
     */
    public static function bestTier(string $query, array $fields, bool $barcodeExact = false): ?int
    {
        if ($barcodeExact) {
            return self::TIER_BARCODE;
        }

        $best = null;
        foreach ($fields as $field) {
            $tier = self::matchTier($query, (string) $field);
            if ($tier !== null && ($best === null || $tier < $best)) {
                $best = $tier;
            }
        }

        return $best;
    }

    private static function splitWords(string $text): array
    {
        return array_values(array_filter(explode(' ', $text), fn ($w) => $w !== ''));
    }

    private static function everyWordPrefixesSome(array $queryWords, array $textWords): bool
    {
        foreach ($queryWords as $qw) {
            $found = false;
            foreach ($textWords as $tw) {
                if (str_starts_with($tw, $qw)) {
                    $found = true;
                    break;
                }
            }
            if (! $found) {
                return false;
            }
        }

        return true;
    }

    private static function fuzzyWordsMatch(array $queryWords, array $textWords): bool
    {
        if ($queryWords === []) {
            return false;
        }

        foreach ($queryWords as $qw) {
            $matched = false;
            $threshold = self::typoThreshold($qw);
            foreach ($textWords as $tw) {
                if (self::levenshtein($qw, $tw) <= $threshold) {
                    $matched = true;
                    break;
                }
            }
            if (! $matched) {
                return false;
            }
        }

        return true;
    }

    /** Shorter words tolerate less drift — see searchMatch.js's own comment. */
    private static function typoThreshold(string $word): int
    {
        return mb_strlen($word, 'UTF-8') <= 4 ? 1 : 2;
    }

    /**
     * Classic Levenshtein edit distance over Unicode codepoints — PHP's
     * native levenshtein() operates on raw BYTES, which is wrong for
     * multi-byte Bengali text (a single character can be 3 UTF-8 bytes),
     * so this re-implements it over an mb_str_split() codepoint array
     * instead. Same codepoint-level (not grapheme-cluster-level)
     * approximation as searchMatch.js's levenshtein() — see that file's
     * own comment.
     */
    public static function levenshtein(string $a, string $b): int
    {
        $ca = mb_str_split($a, 1, 'UTF-8');
        $cb = mb_str_split($b, 1, 'UTF-8');
        $m = count($ca);
        $n = count($cb);
        if ($m === 0) {
            return $n;
        }
        if ($n === 0) {
            return $m;
        }

        $dp = array_fill(0, $m + 1, array_fill(0, $n + 1, 0));
        for ($i = 0; $i <= $m; $i++) {
            $dp[$i][0] = $i;
        }
        for ($j = 0; $j <= $n; $j++) {
            $dp[0][$j] = $j;
        }
        for ($i = 1; $i <= $m; $i++) {
            for ($j = 1; $j <= $n; $j++) {
                $dp[$i][$j] = $ca[$i - 1] === $cb[$j - 1]
                    ? $dp[$i - 1][$j - 1]
                    : 1 + min($dp[$i - 1][$j], $dp[$i][$j - 1], $dp[$i - 1][$j - 1]);
            }
        }

        return $dp[$m][$n];
    }

    /**
     * Second-tier phonetic equivalence (ি/ী, ু/ূ, শ/ষ/স, ন/ণ, জ/য) — see
     * searchMatch.js's phoneticFold() for why this is separate from
     * SearchNormalizer's strict pass, and why য় (yya) is protected via a
     * negative lookahead.
     */
    public static function phoneticFold(string $text): string
    {
        $s = str_replace("\u{09C0}", "\u{09BF}", $text); // ী -> ি
        $s = str_replace("\u{09C2}", "\u{09C1}", $s); // ূ -> ু
        $s = preg_replace('/[\x{09B6}\x{09B7}]/u', "\u{09B8}", $s); // শ/ষ -> স
        $s = str_replace("\u{09A3}", "\u{09A8}", $s); // ণ -> ন
        $s = preg_replace('/\x{09AF}(?!\x{09BC})/u', "\u{099C}", $s); // bare য (not য়) -> জ

        return $s;
    }

    private static function phoneticWordsMatch(array $queryWords, array $textWords): bool
    {
        if ($queryWords === []) {
            return false;
        }

        $foldedQuery = array_map([self::class, 'phoneticFold'], $queryWords);
        $foldedText = array_map([self::class, 'phoneticFold'], $textWords);

        foreach ($foldedQuery as $qw) {
            $found = false;
            foreach ($foldedText as $tw) {
                if (str_starts_with($tw, $qw) || str_contains($tw, $qw)) {
                    $found = true;
                    break;
                }
            }
            if (! $found) {
                return false;
            }
        }

        return true;
    }
}
