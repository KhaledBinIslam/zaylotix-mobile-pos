<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\ProductVariant;
use App\Models\Shop;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesShops;
use Tests\TestCase;

class ShopsCloneFromTemplateTest extends TestCase
{
    use RefreshDatabase, CreatesShops;

    private function sourceWithCatalog(): Shop
    {
        [$source] = $this->createShopWithOwner(['name' => 'Baby Park & Liberty', 'plan' => 'monthly']);
        $this->grantFeature($source, 'shared_stock_pool');
        $this->grantFeature($source, 'barcode_printing');

        $category = ProductCategory::create(['shop_id' => $source->id, 'name' => 'Baby', 'name_en' => 'Baby', 'code' => 'B', 'serial_start' => 20000]);
        $product = Product::create([
            'shop_id' => $source->id, 'category_id' => $category->id,
            'name' => 'B-20001', 'name_en' => 'B-20001', 'barcode' => 'JUMP-B-20001-XXX',
            'cost' => 500, 'price' => 800, 'stock' => 40,
        ]);
        ProductVariant::create([
            'shop_id' => $source->id, 'product_id' => $product->id,
            'size' => '38', 'color' => 'WHITE', 'barcode' => 'JUMP-B-20001-XXX-WHT-38',
            'price' => 800, 'cost' => 500, 'stock' => 12,
        ]);

        return $source->fresh();
    }

    public function test_creates_an_independent_shop_with_the_cloned_catalog_at_zero_stock(): void
    {
        $source = $this->sourceWithCatalog();

        $this->artisan('shops:clone-from-template', [
            '--source' => $source->id, '--name' => 'Liberty Bali', '--phone' => '01799999999', '--owner-password' => 'TestPass123',
        ])->assertSuccessful();

        $newShop = Shop::withoutGlobalScopes()->where('phone', '01799999999')->first();
        $this->assertNotNull($newShop);
        $this->assertSame('Liberty Bali', $newShop->name);
        $this->assertNull($newShop->parent_shop_id);
        $this->assertSame('monthly', $newShop->plan);

        $owner = User::withoutGlobalScopes()->where('shop_id', $newShop->id)->where('role', 'owner')->first();
        $this->assertNotNull($owner);
        $this->assertSame('01799999999', $owner->phone);

        $this->assertTrue($newShop->hasFeature('barcode_printing'));
        $this->assertFalse($newShop->hasFeature('shared_stock_pool'));

        $clonedProduct = Product::withoutGlobalScopes()->where('shop_id', $newShop->id)->where('name', 'B-20001')->first();
        $this->assertNotNull($clonedProduct);
        $this->assertSame(0.0, (float) $clonedProduct->stock);

        $clonedVariant = ProductVariant::withoutGlobalScopes()->where('shop_id', $newShop->id)->where('barcode', 'JUMP-B-20001-XXX-WHT-38')->first();
        $this->assertNotNull($clonedVariant);
        $this->assertSame(0.0, (float) $clonedVariant->stock);

        // no leftover generic business-type seed category alongside the real cloned one
        $this->assertSame(1, ProductCategory::withoutGlobalScopes()->where('shop_id', $newShop->id)->count());
    }

    public function test_dry_run_creates_nothing(): void
    {
        $source = $this->sourceWithCatalog();

        $this->artisan('shops:clone-from-template', [
            '--source' => $source->id, '--name' => 'Liberty Bali', '--phone' => '01799999999', '--owner-password' => 'TestPass123', '--dry-run' => true,
        ])->assertSuccessful();

        $this->assertNull(Shop::withoutGlobalScopes()->where('phone', '01799999999')->first());
    }

    public function test_refuses_a_phone_already_used_by_another_shop(): void
    {
        $source = $this->sourceWithCatalog();

        $this->artisan('shops:clone-from-template', [
            '--source' => $source->id, '--name' => 'Liberty Bali', '--phone' => $source->phone, '--owner-password' => 'TestPass123',
        ])->assertFailed();
    }

    public function test_requires_name_phone_and_password(): void
    {
        $source = $this->sourceWithCatalog();

        $this->artisan('shops:clone-from-template', ['--source' => $source->id])->assertFailed();
    }

    public function test_requires_a_source_shop(): void
    {
        $this->artisan('shops:clone-from-template', [
            '--name' => 'Liberty Bali', '--phone' => '01799999999', '--owner-password' => 'TestPass123',
        ])->assertFailed();
    }
}
