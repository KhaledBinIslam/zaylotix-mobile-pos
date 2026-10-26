<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesShops;
use Tests\TestCase;

/**
 * Regression coverage for docs/FIX_PLAN.md's "price/stock missing during
 * order/sale" fix. PosController::index()/search() were never actually
 * dropping price/stock (confirmed by reading Product's $hidden — empty —
 * and catalogRelations()'s lack of any column select), but this pins that
 * down with a real test so a future column-select change can't silently
 * reintroduce the bug reported this time on a different screen
 * (MoreController/OnlineOrderController, fixed in the same pass).
 */
class PosIndexPropsTest extends TestCase
{
    use RefreshDatabase, CreatesShops;

    public function test_index_sends_price_and_stock_for_a_plain_product(): void
    {
        [$shop, $owner] = $this->createShopWithOwner();
        Product::create(['shop_id' => $shop->id, 'name' => 'চাল', 'cost' => 50, 'price' => 100, 'stock' => 25]);

        $this->actingAs($owner, 'web')->get(route('app.pos'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('products.0.price', fn ($v) => (float) $v === 100.0)
                ->where('products.0.stock', fn ($v) => (float) $v === 25.0));
    }

    public function test_index_sends_each_variants_own_price_and_stock(): void
    {
        [$shop, $owner] = $this->createShopWithOwner();
        $this->grantFeature($shop, 'product_variants');
        $product = Product::create(['shop_id' => $shop->id, 'name' => 'জুতা', 'cost' => 400, 'price' => 500, 'stock' => 0]);
        ProductVariant::create(['shop_id' => $shop->id, 'product_id' => $product->id, 'size' => '40', 'color' => 'কালো', 'price' => 600, 'stock' => 8]);

        $this->actingAs($owner, 'web')->get(route('app.pos'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('products.0.variants.0.price', fn ($v) => (float) $v === 600.0)
                ->where('products.0.variants.0.stock', fn ($v) => (float) $v === 8.0));
    }

    public function test_index_sends_price_and_stock_for_untracked_and_toggle_stock_modes(): void
    {
        [$shop, $owner] = $this->createShopWithOwner();
        Product::create(['shop_id' => $shop->id, 'name' => 'লবণ', 'cost' => 10, 'price' => 20, 'stock' => 0, 'stock_mode' => 'untracked']);
        Product::create(['shop_id' => $shop->id, 'name' => 'ডিশ অফ দ্য ডে', 'cost' => 80, 'price' => 150, 'stock' => 1, 'stock_mode' => 'toggle']);

        $this->actingAs($owner, 'web')->get(route('app.pos'))
            ->assertOk()
            ->assertInertia(function ($page) {
                $products = $page->toArray()['props']['products'];
                $this->assertCount(2, $products);
                foreach ($products as $p) {
                    $this->assertArrayHasKey('price', $p);
                    $this->assertArrayHasKey('stock', $p);
                }
            });
    }

    public function test_search_endpoint_sends_price_and_stock_too(): void
    {
        [$shop, $owner] = $this->createShopWithOwner();
        Product::create(['shop_id' => $shop->id, 'name' => 'চিনি', 'cost' => 60, 'price' => 90, 'stock' => 40]);

        $response = $this->actingAs($owner, 'web')->getJson(route('app.pos.search', ['q' => 'চিনি']));

        $response->assertOk();
        $product = $response->json('products')[0];
        $this->assertEquals(90, $product['price']);
        $this->assertEquals(40, $product['stock']);
        $this->assertArrayNotHasKey('cost', $product);
    }

    public function test_cost_price_never_reaches_the_pos_screen_even_for_the_owner(): void
    {
        // confirmed, pre-existing leak found while building this fix:
        // index()'s full-catalog payload (and search()/catalogSyncChunk())
        // serialized every Product column with no select — cost included —
        // even though neither Pos/Index.vue nor Clothing/Pos.vue ever reads
        // it. Fixed via PosController::hideCost(). Checked for the owner
        // too, not just a cashier — POS has never had a legitimate reason
        // to carry cost regardless of role; Stock/Reports are where that
        // belongs.
        [$shop, $owner] = $this->createShopWithOwner();
        Product::create(['shop_id' => $shop->id, 'name' => 'চাল', 'cost' => 50, 'price' => 100, 'stock' => 25]);

        $this->actingAs($owner, 'web')->get(route('app.pos'))
            ->assertOk()
            ->assertInertia(function ($page) {
                $products = $page->toArray()['props']['products'];
                $this->assertArrayNotHasKey('cost', $products[0]);
            });
    }

    public function test_a_cashier_never_sees_another_shops_products_or_cost_price(): void
    {
        [$shopA] = $this->createShopWithOwner();
        [$shopB, $ownerB] = $this->createShopWithOwner();
        Product::create(['shop_id' => $shopA->id, 'name' => 'Other Shop Item', 'cost' => 999, 'price' => 1000, 'stock' => 5]);
        Product::create(['shop_id' => $shopB->id, 'name' => 'My Item', 'cost' => 10, 'price' => 20, 'stock' => 5]);
        $cashier = User::create(['shop_id' => $shopB->id, 'name' => 'Cashier', 'phone' => '019'.random_int(10000000, 99999999), 'password' => 'password', 'role' => 'staff', 'permissions' => ['pos'], 'lang' => 'bn']);

        $this->actingAs($cashier, 'web')->get(route('app.pos'))
            ->assertOk()
            ->assertInertia(function ($page) {
                $products = $page->toArray()['props']['products'];
                $this->assertCount(1, $products);
                $this->assertSame('My Item', $products[0]['name']);
                // not a secrecy mechanism (a cashier with DB access could
                // still see it), but the normal POS payload has never had
                // any reason to carry cost — confirms this fix didn't add one
                $this->assertArrayNotHasKey('cost', $products[0]);
            });
    }
}
