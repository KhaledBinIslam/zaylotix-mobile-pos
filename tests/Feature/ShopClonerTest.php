<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\ProductVariant;
use App\Models\Shop;
use App\Models\Unit;
use App\Support\ArticleSerial;
use App\Support\ShopCloner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesShops;
use Tests\TestCase;

/**
 * Liberty Bali: cloning Baby Park's catalog into a brand-new, independent
 * shop, every stock figure forced to 0 regardless of shared_stock_pool.
 */
class ShopClonerTest extends TestCase
{
    use RefreshDatabase, CreatesShops;

    public function test_clones_categories_units_products_and_variants_with_stock_zeroed(): void
    {
        [$source] = $this->createShopWithOwner();
        [$target] = $this->createShopWithOwner();

        $category = ProductCategory::create(['shop_id' => $source->id, 'name' => 'Baby', 'name_en' => 'Baby', 'code' => 'B', 'serial_start' => 20000]);
        $unit = Unit::create(['shop_id' => $source->id, 'name' => 'Pair', 'name_en' => 'Pair', 'code' => 'pr']);
        $product = Product::create([
            'shop_id' => $source->id, 'category_id' => $category->id, 'unit_id' => $unit->id,
            'name' => 'B-20001', 'name_en' => 'B-20001', 'barcode' => 'JUMP-B-20001-XXX',
            'cost' => 500, 'price' => 800, 'stock' => 40,
        ]);
        ProductVariant::create([
            'shop_id' => $source->id, 'product_id' => $product->id,
            'size' => '38', 'color' => 'WHITE', 'barcode' => 'JUMP-B-20001-XXX-WHT-38',
            'price' => 800, 'cost' => 500, 'stock' => 12,
        ]);

        $counts = ShopCloner::cloneCatalog($source, $target);

        $this->assertSame(['categories' => 1, 'units' => 1, 'products' => 1, 'variants' => 1], $counts);

        $clonedCategory = ProductCategory::withoutGlobalScopes()->where('shop_id', $target->id)->first();
        $this->assertSame('B', $clonedCategory->code);
        $this->assertSame(20000, $clonedCategory->serial_start);

        $clonedProduct = Product::withoutGlobalScopes()->where('shop_id', $target->id)->first();
        $this->assertSame('B-20001', $clonedProduct->name);
        $this->assertSame('JUMP-B-20001-XXX', $clonedProduct->barcode);
        $this->assertSame(0.0, (float) $clonedProduct->stock);

        $clonedVariant = ProductVariant::withoutGlobalScopes()->where('shop_id', $target->id)->first();
        $this->assertSame('JUMP-B-20001-XXX-WHT-38', $clonedVariant->barcode);
        $this->assertSame(0.0, (float) $clonedVariant->stock);
    }

    public function test_feature_keys_exclude_branch_only_features(): void
    {
        [$source] = $this->createShopWithOwner();
        $this->grantFeature($source, 'shared_stock_pool');
        $this->grantFeature($source, 'shared_catalog');
        $this->grantFeature($source, 'purchases');

        $keys = ShopCloner::featureKeysFrom($source);

        $this->assertContains('purchases', $keys);
        $this->assertNotContains('shared_stock_pool', $keys);
        $this->assertNotContains('shared_catalog', $keys);
    }

    public function test_settings_from_copies_the_declared_shop_level_columns(): void
    {
        [$source] = $this->createShopWithOwner(['vat_mode' => 'inclusive', 'vat_rate' => 7.5]);
        $source->forceFill(['color_code_map' => ['WHITE' => 'WHT']])->save();

        $attrs = ShopCloner::settingsFrom($source);

        $this->assertSame('inclusive', $attrs['vat_mode']);
        $this->assertEquals(7.5, $attrs['vat_rate']);
        $this->assertSame(['WHITE' => 'WHT'], $attrs['color_code_map']);
    }

    public function test_bump_all_counters_prevents_a_fresh_article_colliding_with_a_cloned_one(): void
    {
        [$source] = $this->createShopWithOwner();
        [$target] = $this->createShopWithOwner();

        $category = ProductCategory::create(['shop_id' => $source->id, 'name' => 'Baby', 'name_en' => 'Baby', 'code' => 'B', 'serial_start' => 20000]);
        Product::create(['shop_id' => $source->id, 'category_id' => $category->id, 'name' => 'B-20001', 'name_en' => 'B-20001', 'cost' => 100, 'price' => 150, 'stock' => 5]);

        ShopCloner::cloneCatalog($source, $target);
        ShopCloner::bumpAllCounters($target);

        $next = ArticleSerial::next($target, 'B', 20000);

        $this->assertSame(20002, $next);
    }
}
