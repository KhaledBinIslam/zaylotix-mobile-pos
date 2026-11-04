<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\ProductVariant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesShops;
use Tests\TestCase;

/**
 * Khaled: "serail dhore kontar stock mullo koto ache ... stock mulle
 * click korle details e jate ese jai." The Accounts page's one figure
 * opens into a per-article breakdown, and the two must always agree —
 * same arithmetic, applied row by row.
 */
class StockValueDetailTest extends TestCase
{
    use RefreshDatabase, CreatesShops;

    public function test_the_detail_total_equals_the_accounts_card_figure(): void
    {
        [$shop, $owner] = $this->createShopWithOwner();
        $this->grantFeature($shop, 'accounts');

        // a variant product where one size has its OWN cost (size-group
        // pricing) and one falls back to the article's headline cost
        $article = Product::create(['shop_id' => $shop->id, 'name' => 'L-90000', 'cost' => 700, 'price' => 1200, 'stock' => 5]);
        ProductVariant::create(['shop_id' => $shop->id, 'product_id' => $article->id, 'size' => '36', 'stock' => 2, 'cost' => 650]);
        ProductVariant::create(['shop_id' => $shop->id, 'product_id' => $article->id, 'size' => '37', 'stock' => 3, 'cost' => null]);
        // and a plain product
        Product::create(['shop_id' => $shop->id, 'name' => 'Belt', 'cost' => 100, 'price' => 250, 'stock' => 4]);

        $expected = (2 * 650) + (3 * 700) + (4 * 100); // 3800

        $index = $this->actingAs($owner, 'web')->get(route('app.accounts'));
        $index->assertOk()->assertInertia(fn ($page) => $page->where('stockValue', $expected)->etc());

        $this->actingAs($owner, 'web')->get(route('app.accounts.stockValue'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('App/Accounts/StockValue')
                ->where('total', $expected)
                ->where('rows.0.name', 'L-90000') // biggest value first
                ->where('rows.0.value', 2 * 650 + 3 * 700)
                ->where('rows.0.variants.0.unit_cost', 650)
                ->where('rows.0.variants.1.unit_cost', 700) // fallback to the article's cost
                ->where('rows.1.name', 'Belt')
                ->etc());
    }

    public function test_another_shops_stock_never_leaks_into_the_detail(): void
    {
        [$shopA, $ownerA] = $this->createShopWithOwner();
        $this->grantFeature($shopA, 'accounts');
        [$shopB] = $this->createShopWithOwner();
        Product::create(['shop_id' => $shopB->id, 'name' => 'Other', 'cost' => 999, 'price' => 1500, 'stock' => 9]);

        $this->actingAs($ownerA, 'web')->get(route('app.accounts.stockValue'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('total', 0)->where('rows', [])->etc());
    }
}
