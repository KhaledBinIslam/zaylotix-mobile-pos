<?php

namespace Tests\Unit;

use App\Support\SearchNormalizer;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Shared fixture with resources/js/support/__tests__/searchNormalize.test.js
 * (tests/fixtures/search_normalize_cases.json) — proves PHP and JS agree on
 * every case, not just that each one independently "looks right".
 */
class SearchNormalizerTest extends TestCase
{
    public static function casesProvider(): array
    {
        $cases = json_decode(file_get_contents(__DIR__.'/../fixtures/search_normalize_cases.json'), true, flags: JSON_THROW_ON_ERROR);

        $named = [];
        foreach ($cases as $case) {
            $named[$case['name']] = [$case['input'], $case['expected']];
        }

        return $named;
    }

    #[DataProvider('casesProvider')]
    public function test_normalize_matches_the_shared_fixture(string $input, string $expected): void
    {
        $this->assertSame($expected, SearchNormalizer::normalize($input));
    }

    public function test_normalizing_an_already_normalized_string_is_idempotent(): void
    {
        $s = SearchNormalizer::normalize('সয়াসস ২০০ মিলি  Soy Sauce');
        $this->assertSame($s, SearchNormalizer::normalize($s));
    }

    public function test_null_normalizes_to_empty_string(): void
    {
        $this->assertSame('', SearchNormalizer::normalize(null));
    }
}
