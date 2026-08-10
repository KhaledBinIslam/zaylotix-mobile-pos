<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\RestaurantTable;
use App\Models\Shop;
use App\Support\Tenancy;
use Database\Seeders\BusinessTypeSeeder;
use Database\Seeders\DemoShopSeeder;
use Database\Seeders\FeatureSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Covers the incident this seeder's own docblock mentions: every demo
 * shop's products (and the restaurant's tables, and Khaled Enterprise's
 * sample sales) were found deleted directly against the dev database —
 * not by this seeder, but re-running it is how they got restored, which
 * only worked because it's idempotent. Also locks in the two real product
 * bugs found alongside that: restaurant menu items seeded with stock=0
 * made every "add to order" click silently do nothing (see Order.vue's
 * addItem()), and Fashion Point never had the `product_variants` feature
 * granted, so its color/size picker could never appear regardless of what
 * products existed.
 */
class DemoShopSeederTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(BusinessTypeSeeder::class);
        $this->seed(FeatureSeeder::class);
    }

    public function test_seeds_every_demo_shop_with_a_nonzero_product_catalog(): void
    {
        $this->seed(DemoShopSeeder::class);

        foreach (['01979894356', '01700000002', '01700000003', '01700000004', '01700000005', '01700000006', '01700000007', '01700000008'] as $phone) {
            $shop = Shop::withoutGlobalScopes()->where('phone', $phone)->first();
            $this->assertNotNull($shop, "demo shop with phone {$phone} should exist");

            $count = Product::withoutGlobalScopes()->where('shop_id', $shop->id)->count();
            $this->assertGreaterThan(0, $count, "{$shop->name} should have at least one seeded product");
        }
    }

    public function test_restaurant_menu_items_have_real_sellable_stock(): void
    {
        $this->seed(DemoShopSeeder::class);

        $shop = Shop::withoutGlobalScopes()->where('phone', '01700000008')->first();
        $items = Product::withoutGlobalScopes()->where('shop_id', $shop->id)->get();

        $this->assertGreaterThan(0, $items->count());
        foreach ($items as $item) {
            $this->assertGreaterThan(0, (float) $item->stock, "{$item->name} must have real stock — 0 makes every order-screen click silently fail (see Order.vue's addItem())");
        }

        $tables = RestaurantTable::withoutGlobalScopes()->where('shop_id', $shop->id)->count();
        $this->assertSame(4, $tables);
    }

    public function test_fashion_point_gets_the_product_variants_feature_and_a_real_variant_product(): void
    {
        $this->seed(DemoShopSeeder::class);

        $shop = Shop::withoutGlobalScopes()->where('phone', '01700000004')->first();
        $this->assertTrue($shop->hasFeature('product_variants'), 'without this, Clothing/Pos.vue never shows the color/size picker at all, regardless of product data');

        Tenancy::set($shop->id);
        $shirt = Product::where('name', 'কটন শার্ট')->with('variants')->first();
        Tenancy::clear();

        $this->assertNotNull($shirt);
        $this->assertGreaterThanOrEqual(2, $shirt->variants->count());
        $this->assertEquals($shirt->variants->sum('stock'), $shirt->stock, "parent stock must equal the sum of its variants' stock");
    }

    /** The actual incident this locks in: re-seeding an already-fully-seeded demo shop must not error or duplicate anything. */
    public function test_running_the_seeder_twice_is_safe_and_does_not_duplicate(): void
    {
        $this->seed(DemoShopSeeder::class);
        $firstShopCount = Shop::withoutGlobalScopes()->count();
        $firstProductCount = Product::withoutGlobalScopes()->count();

        $this->seed(DemoShopSeeder::class);

        $this->assertSame($firstShopCount, Shop::withoutGlobalScopes()->count(), 're-running must not create duplicate shops');
        $this->assertSame($firstProductCount, Product::withoutGlobalScopes()->count(), 're-running must not create duplicate products');
    }

    /** Mirrors the real incident precisely: a demo shop survives, but something else wiped only its products/tables/sales. */
    public function test_repairs_a_demo_shop_that_lost_only_its_products(): void
    {
        $this->seed(DemoShopSeeder::class);

        $shop = Shop::withoutGlobalScopes()->where('phone', '01700000008')->first();
        Tenancy::set($shop->id);
        Product::query()->forceDelete();
        RestaurantTable::query()->forceDelete();
        Tenancy::clear();

        $this->assertSame(0, Product::withoutGlobalScopes()->where('shop_id', $shop->id)->count());

        $this->seed(DemoShopSeeder::class);

        $this->assertGreaterThan(0, Product::withoutGlobalScopes()->where('shop_id', $shop->id)->count());
        $this->assertSame(4, RestaurantTable::withoutGlobalScopes()->where('shop_id', $shop->id)->count());
        // the shop/owner itself must have been REUSED, not recreated
        $this->assertSame(1, Shop::withoutGlobalScopes()->where('phone', '01700000008')->count());
    }
}
