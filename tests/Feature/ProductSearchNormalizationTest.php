<?php

namespace Tests\Feature;

use App\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesShops;
use Tests\TestCase;

/**
 * End-to-end proof for docs/FIX_PLAN.md's search-normalization fix: a
 * product name saved with ONE Unicode spelling of য়/ড়/ঢ়/Bengali digits
 * is still found when searched with the OTHER spelling, through the real
 * HTTP endpoints a cashier actually hits — not just the unit-level
 * SearchNormalizer/SearchMatcher tests.
 */
class ProductSearchNormalizationTest extends TestCase
{
    use RefreshDatabase, CreatesShops;

    public function test_pos_search_finds_a_product_saved_with_the_precomposed_yya_when_searched_with_the_decomposed_spelling(): void
    {
        [$shop, $owner] = $this->createShopWithOwner();
        // "ধনিয়া" (dhonia/coriander) saved with the precomposed য় (U+09DF)
        Product::create(['shop_id' => $shop->id, 'name' => "\u{09a7}\u{09a8}\u{09bf}\u{09df}\u{09be}", 'cost' => 10, 'price' => 20, 'stock' => 50]);

        // typed with the decomposed spelling (য + ়, U+09AF+U+09BC)
        $response = $this->actingAs($owner, 'web')
            ->getJson(route('app.pos.search', ['q' => "\u{09a7}\u{09a8}\u{09bf}\u{09af}\u{09bc}\u{09be}"]));

        $response->assertOk();
        $this->assertCount(1, $response->json('products'));
    }

    public function test_lightweight_product_search_endpoint_has_the_same_normalization(): void
    {
        [$shop, $owner] = $this->createShopWithOwner();
        Product::create(['shop_id' => $shop->id, 'name' => "\u{09b8}\u{09df}\u{09be}\u{09b8}\u{09b8}", 'cost' => 10, 'price' => 20, 'stock' => 10]); // সয়াসস, precomposed

        $response = $this->actingAs($owner, 'web')
            ->getJson(route('app.products.search', ['q' => "\u{09b8}\u{09af}\u{09bc}\u{09be}\u{09b8}\u{09b8}"])); // decomposed

        $response->assertOk();
        $this->assertCount(1, $response->json('results'));
    }

    public function test_bengali_digits_in_the_name_match_an_ascii_digit_search_and_vice_versa(): void
    {
        [$shop, $owner] = $this->createShopWithOwner();
        // "২০০ মিলি" (200 ml) — Bengali digits
        Product::create(['shop_id' => $shop->id, 'name' => "\u{09e8}\u{09e6}\u{09e6} \u{09ae}\u{09bf}\u{09b2}\u{09bf}", 'cost' => 10, 'price' => 20, 'stock' => 10]);

        $response = $this->actingAs($owner, 'web')->getJson(route('app.pos.search', ['q' => '200']));

        $response->assertOk();
        $this->assertCount(1, $response->json('products'));
    }

    public function test_word_order_does_not_matter_in_the_stock_page_search(): void
    {
        [$shop, $owner] = $this->createShopWithOwner();
        // "টমেটো সস" (tomato sauce)
        Product::create(['shop_id' => $shop->id, 'name' => "\u{099f}\u{09ae}\u{09c7}\u{099f}\u{09cb} \u{09b8}\u{09b8}", 'cost' => 10, 'price' => 20, 'stock' => 10]);

        $response = $this->actingAs($owner, 'web')
            ->get(route('app.stock', ['q' => "\u{09b8}\u{09b8} \u{099f}\u{09ae}\u{09c7}\u{099f}\u{09cb}"])); // "সস টমেটো", reversed

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page->has('products.data', 1));
    }

    public function test_a_close_typo_still_finds_the_product_via_the_fuzzy_fallback(): void
    {
        [$shop, $owner] = $this->createShopWithOwner();
        Product::create(['shop_id' => $shop->id, 'name' => "\u{099f}\u{09ae}\u{09c7}\u{099f}\u{09cb}", 'cost' => 10, 'price' => 20, 'stock' => 10]); // টমেটো

        $response = $this->actingAs($owner, 'web')
            ->getJson(route('app.pos.search', ['q' => "\u{099f}\u{09ae}\u{099f}\u{09cb}"])); // টমটো, missing ে — one-edit typo

        $response->assertOk();
        $this->assertCount(1, $response->json('products'));
    }

    public function test_exact_barcode_match_ranks_first_even_when_a_fuzzier_match_also_qualifies(): void
    {
        [$shop, $owner] = $this->createShopWithOwner();
        $decoy = Product::create(['shop_id' => $shop->id, 'name' => 'চাল সরু', 'barcode' => '111', 'cost' => 10, 'price' => 20, 'stock' => 10]);
        $target = Product::create(['shop_id' => $shop->id, 'name' => 'অন্য পণ্য', 'barcode' => '12345', 'cost' => 10, 'price' => 20, 'stock' => 10]);

        $response = $this->actingAs($owner, 'web')->getJson(route('app.pos.search', ['q' => '12345']));

        $response->assertOk();
        $this->assertSame($target->id, $response->json('products.0.id'));
    }

    public function test_customer_search_falls_back_to_normalized_matching_on_a_unicode_spelling_mismatch(): void
    {
        [$shop, $owner] = $this->createShopWithOwner();
        \App\Models\Customer::create([
            'shop_id' => $shop->id,
            'name' => "\u{09a7}\u{09a8}\u{09bf}\u{09df}\u{09be} \u{0995}\u{09b0}\u{09c0}\u{09ae}", // ধনিয়া করীম, precomposed য়
            'phone' => '01700000000',
        ]);

        $response = $this->actingAs($owner, 'web')
            ->getJson(route('app.customers.search', ['q' => "\u{09a7}\u{09a8}\u{09bf}\u{09af}\u{09bc}\u{09be}"])); // decomposed

        $response->assertOk();
        $this->assertCount(1, $response->json('results'));
    }

    public function test_another_shops_product_never_matches_regardless_of_normalization(): void
    {
        [$shopA] = $this->createShopWithOwner();
        [$shopB, $ownerB] = $this->createShopWithOwner();
        Product::create(['shop_id' => $shopA->id, 'name' => "\u{09a7}\u{09a8}\u{09bf}\u{09df}\u{09be}", 'cost' => 10, 'price' => 20, 'stock' => 10]);

        $response = $this->actingAs($ownerB, 'web')
            ->getJson(route('app.pos.search', ['q' => "\u{09a7}\u{09a8}\u{09bf}\u{09af}\u{09bc}\u{09be}"]));

        $response->assertOk();
        $this->assertCount(0, $response->json('products'));
    }
}
