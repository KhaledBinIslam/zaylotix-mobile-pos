<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\ProductVariant;
use App\Models\Shop;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SetupDemoClothingShopTest extends TestCase
{
    use RefreshDatabase;

    private function runSetup(array $extra = []): Shop
    {
        $this->artisan('shop:setup-demo-clothing', ['--phone' => '01799000088', '--password' => 'demo1234', '--articles' => 3, ...$extra])
            ->assertSuccessful();

        return Shop::withoutGlobalScopes()->where('phone', '01799000088')->firstOrFail();
    }

    public function test_creates_a_stocked_article_wise_clothing_demo(): void
    {
        $shop = $this->runSetup();

        $this->assertSame('demo', $shop->account_type);
        $this->assertSame('clothing', $shop->businessType?->slug);

        $categories = ProductCategory::withoutGlobalScopes()->where('shop_id', $shop->id)->pluck('code', 'name');
        $this->assertSame('P', $categories['প্যান্ট']);
        $this->assertSame('R', $categories['থ্রি-পিস']);

        // 9 categories x 3 articles
        $this->assertSame(27, Product::withoutGlobalScopes()->where('shop_id', $shop->id)->whereNull('deleted_at')->count());

        $pant = Product::withoutGlobalScopes()->where('shop_id', $shop->id)->where('name', 'P-10001')->firstOrFail();
        $variants = ProductVariant::withoutGlobalScopes()->where('product_id', $pant->id)->whereNull('deleted_at')->get();
        $this->assertCount(10, $variants); // 5 sizes x 2 colors
        $this->assertEquals(20, (float) $variants->first()->stock);
        $this->assertEquals(200, (float) $pant->stock); // mirror = 10 x 20
        $this->assertSame('DK-P-10001-BK-28', $variants->firstWhere('size', '28')->barcode);

        // কাপড় (পিস) is a plain, variant-less article with its own stock
        $fabric = Product::withoutGlobalScopes()->where('shop_id', $shop->id)->where('name', 'K-10001')->firstOrFail();
        $this->assertSame(0, ProductVariant::withoutGlobalScopes()->where('product_id', $fabric->id)->count());
        $this->assertEquals(40, (float) $fabric->stock);
    }

    public function test_rerun_tops_up_instead_of_duplicating(): void
    {
        $shop = $this->runSetup();
        $pant = Product::withoutGlobalScopes()->where('shop_id', $shop->id)->where('name', 'P-10001')->firstOrFail();
        ProductVariant::withoutGlobalScopes()->where('product_id', $pant->id)->update(['stock' => 1]);

        $again = $this->runSetup();

        $this->assertSame($shop->id, $again->id);
        $this->assertSame(27, Product::withoutGlobalScopes()->where('shop_id', $shop->id)->whereNull('deleted_at')->count());
        $this->assertEquals(20, (float) ProductVariant::withoutGlobalScopes()->where('product_id', $pant->id)->first()->stock);
    }

    public function test_seed_demo_articles_adds_a_footwear_catalog_to_an_existing_demo_shop(): void
    {
        $shop = $this->runSetup();

        $this->artisan('shops:seed-demo-articles', ['--shop' => $shop->id, '--catalog' => 'footwear', '--articles' => 2, '--qty' => 10])
            ->assertSuccessful();

        $gents = Product::withoutGlobalScopes()->where('shop_id', $shop->id)->where('name', 'GS-10001')->firstOrFail();
        $variants = ProductVariant::withoutGlobalScopes()->where('product_id', $gents->id)->whereNull('deleted_at')->get();
        $this->assertCount(12, $variants); // 6 sizes x 2 colors
        $this->assertEquals(10, (float) $variants->first()->stock);
        $this->assertSame('DF-GS-10001-BK-39', $variants->firstWhere('size', '39')->barcode);

        // the clothing articles it already had are untouched
        $this->assertNotNull(Product::withoutGlobalScopes()->where('shop_id', $shop->id)->where('name', 'P-10001')->first());
    }

    public function test_seed_demo_articles_refuses_a_paid_shop_without_force(): void
    {
        $shop = $this->runSetup();
        Shop::withoutGlobalScopes()->whereKey($shop->id)->update(['account_type' => 'paid']);

        $this->artisan('shops:seed-demo-articles', ['--shop' => $shop->id, '--catalog' => 'footwear'])->assertFailed();
        $this->assertNull(Product::withoutGlobalScopes()->where('shop_id', $shop->id)->where('name', 'GS-10001')->first());
    }

    public function test_rollback_removes_the_demo_when_no_sales_exist(): void
    {
        $shop = $this->runSetup();

        $this->artisan('shop:setup-demo-clothing', ['--phone' => '01799000088', '--rollback' => true])->assertSuccessful();

        $this->assertNull(Shop::withoutGlobalScopes()->find($shop->id));
        $this->assertSame(0, Product::withoutGlobalScopes()->where('shop_id', $shop->id)->count());
    }
}
