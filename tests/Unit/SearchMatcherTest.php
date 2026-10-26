<?php

namespace Tests\Unit;

use App\Support\SearchMatcher;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Shared fixture with resources/js/support/__tests__/searchMatch.test.js
 * (tests/fixtures/search_match_cases.json).
 */
class SearchMatcherTest extends TestCase
{
    public static function casesProvider(): array
    {
        $cases = json_decode(file_get_contents(__DIR__.'/../fixtures/search_match_cases.json'), true, flags: JSON_THROW_ON_ERROR);

        $named = [];
        foreach ($cases as $case) {
            $named[$case['name']] = [$case['query'], $case['text'], $case['expectedTier']];
        }

        return $named;
    }

    #[DataProvider('casesProvider')]
    public function test_match_tier_matches_the_shared_fixture(string $query, string $text, ?int $expectedTier): void
    {
        $this->assertSame($expectedTier, SearchMatcher::matchTier($query, $text));
    }

    public function test_levenshtein_is_zero_for_identical_strings(): void
    {
        $this->assertSame(0, SearchMatcher::levenshtein('abc', 'abc'));
    }

    public function test_levenshtein_counts_a_single_insertion_as_distance_one(): void
    {
        $this->assertSame(1, SearchMatcher::levenshtein('টমটো', 'টমেটো'));
    }

    public function test_levenshtein_handles_empty_strings(): void
    {
        $this->assertSame(3, SearchMatcher::levenshtein('', 'abc'));
        $this->assertSame(3, SearchMatcher::levenshtein('abc', ''));
    }

    public function test_best_tier_returns_barcode_regardless_of_text_fields_when_exact(): void
    {
        $this->assertSame(SearchMatcher::TIER_BARCODE, SearchMatcher::bestTier('anything', ['completely unrelated'], true));
    }

    public function test_best_tier_returns_the_best_lowest_tier_across_multiple_fields(): void
    {
        $tier = SearchMatcher::bestTier('soy', ['a soy product', 'soy sauce']);
        $this->assertSame(SearchMatcher::TIER_PREFIX, $tier);
    }

    public function test_best_tier_returns_null_when_no_field_matches(): void
    {
        $this->assertNull(SearchMatcher::bestTier('xyz', ['abc', 'def']));
    }
}
