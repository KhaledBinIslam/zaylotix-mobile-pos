<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\SalesChannel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesShops;
use Tests\TestCase;

/**
 * Regression coverage for docs/FIX_PLAN.md's "price/stock missing during
 * order/sale" fix — OnlineOrderController::create()'s explicit column
 * list never asked for `stock`, so a non-variant product's current stock
 * could never show in the online-order product picker. See
 * tests/Feature/OnlineOrderTest.php for the rest of this module's coverage
 * (store()/lifecycle) — this file is scoped to create()'s props only.
 */
class OnlineOrderCreatePropsTest extends TestCase
{
    use RefreshDatabase, CreatesShops;

    public function test_products_prop_carries_stock_for_a_plain_product(): void
    {
        [$shop, $owner] = $this->createShopWithOwner();
        $this->grantFeature($shop, 'online_business');
        SalesChannel::create(['shop_id' => $shop->id, 'name' => 'Page A', 'platform_type' => 'facebook', 'order_prefix' => 'FB-']);
        Product::create(['shop_id' => $shop->id, 'name' => 'চাল', 'cost' => 50, 'price' => 100, 'stock' => 25]);

        $this->actingAs($owner, 'web')->get(route('app.onlineOrders.create'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('products.0.stock', fn ($v) => (float) $v === 25.0));
    }

    public function test_products_prop_still_carries_each_variants_own_stock(): void
    {
        [$shop, $owner] = $this->createShopWithOwner();
        $this->grantFeature($shop, 'online_business');
        $this->grantFeature($shop, 'product_variants');
        SalesChannel::create(['shop_id' => $shop->id, 'name' => 'Page A', 'platform_type' => 'facebook', 'order_prefix' => 'FB-']);
        $product = Product::create(['shop_id' => $shop->id, 'name' => 'জুতা', 'cost' => 400, 'price' => 500, 'stock' => 0]);
        ProductVariant::create(['shop_id' => $shop->id, 'product_id' => $product->id, 'size' => '40', 'color' => 'কালো', 'stock' => 12]);

        $this->actingAs($owner, 'web')->get(route('app.onlineOrders.create'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('products.0.variants.0.stock', fn ($v) => (float) $v === 12.0));
    }
}
