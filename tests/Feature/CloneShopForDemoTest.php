<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\ProductSource;
use App\Models\ProductVariant;
use App\Models\Shop;
use App\Models\Supplier;
use App\Models\User;
use App\Support\Tenancy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\Concerns\CreatesShops;
use Tests\TestCase;

/**
 * Khaled: "amar ambassador eta dekhai sell ante pare" — an ambassador
 * sales-demo shop seeded with a verbatim copy of a real shop's catalog.
 * The command this covers must never create any live link back to the
 * source (no parent_shop_id, no shared stock pool) and must leave real
 * financial/customer data out even while copying the catalog verbatim.
 */
class CloneShopForDemoTest extends TestCase
{
    use RefreshDatabase, CreatesShops;

    public function test_clones_categories_products_variants_and_stock(): void
    {
        [$source] = $this->createShopWithOwner();
        $this->grantFeature($source, 'product_variants');
        Tenancy::set($source->id);

        $cat = ProductCategory::create(['shop_id' => $source->id, 'name' => 'জুতা', 'name_en' => 'Shoes', 'emoji' => '👟']);
        $plain = Product::create(['shop_id' => $source->id, 'category_id' => $cat->id, 'name' => 'Sandal', 'cost' => 300, 'price' => 500, 'stock' => 40, 'barcode' => 'B-1']);
        $variantProduct = Product::create(['shop_id' => $source->id, 'category_id' => $cat->id, 'name' => 'Boot', 'cost' => 800, 'price' => 1200, 'stock' => 0, 'barcode' => 'B-2']);
        ProductVariant::create(['shop_id' => $source->id, 'product_id' => $variantProduct->id, 'size' => '42', 'color' => 'Black', 'stock' => 17, 'barcode' => 'V-1']);
        Tenancy::clear();

        $exitCode = Artisan::call('demo:clone-shop', [
            'source_shop_id' => $source->id,
            'name' => 'Demo Shoe Shop',
            'owner_email' => 'ambassador-demo-1@example.test',
            'owner_password' => 'password123',
        ]);
        $this->assertSame(0, $exitCode);

        $demo = Shop::withoutGlobalScopes()->where('name', 'Demo Shoe Shop')->firstOrFail();

        // never a real branch of the source
        $this->assertNull($demo->parent_shop_id);
        $this->assertNotSame($source->id, $demo->id);

        $owner = User::where('email', 'ambassador-demo-1@example.test')->first();
        $this->assertNotNull($owner);
        $this->assertSame($demo->id, $owner->shop_id);
        $this->assertSame('owner', $owner->role);

        $demoPlain = Product::withoutGlobalScopes()->where('shop_id', $demo->id)->where('name', 'Sandal')->first();
        $this->assertNotNull($demoPlain);
        $this->assertNotSame($plain->id, $demoPlain->id); // a fresh row, not a shared one
        $this->assertSame(40.0, (float) $demoPlain->stock); // verbatim stock, not reset to 0

        $demoVariantProduct = Product::withoutGlobalScopes()->where('shop_id', $demo->id)->where('name', 'Boot')->first();
        $demoVariant = ProductVariant::withoutGlobalScopes()->where('shop_id', $demo->id)->where('product_id', $demoVariantProduct->id)->first();
        $this->assertNotNull($demoVariant);
        $this->assertSame(17.0, (float) $demoVariant->stock);
    }

    public function test_demo_stock_changes_never_touch_the_source_shop(): void
    {
        [$source] = $this->createShopWithOwner();
        Tenancy::set($source->id);
        $p = Product::create(['shop_id' => $source->id, 'name' => 'Sandal', 'cost' => 300, 'price' => 500, 'stock' => 40]);
        Tenancy::clear();

        Artisan::call('demo:clone-shop', [
            'source_shop_id' => $source->id,
            'name' => 'Demo Shoe Shop',
            'owner_email' => 'ambassador-demo-2@example.test',
            'owner_password' => 'password123',
        ]);

        $demo = Shop::withoutGlobalScopes()->where('name', 'Demo Shoe Shop')->firstOrFail();
        $demoProduct = Product::withoutGlobalScopes()->where('shop_id', $demo->id)->where('name', 'Sandal')->firstOrFail();

        // simulate the ambassador selling a unit in the demo
        Product::withoutGlobalScopes()->whereKey($demoProduct->id)->update(['stock' => 39]);

        $this->assertSame(40.0, (float) Product::withoutGlobalScopes()->whereKey($p->id)->value('stock'));
    }

    public function test_clones_suppliers_and_source_lines_but_resets_payable(): void
    {
        [$source] = $this->createShopWithOwner();
        $this->grantFeature($source, 'suppliers');
        $this->grantFeature($source, 'source_lines');
        Tenancy::set($source->id);

        $supplier = Supplier::create(['shop_id' => $source->id, 'name' => 'Sujon', 'payable' => 54000]);
        $p = Product::create(['shop_id' => $source->id, 'name' => 'Boiler Suit', 'cost' => 650, 'price' => 1090, 'stock' => 10, 'barcode' => 'BS-1']);
        ProductSource::create(['shop_id' => $source->id, 'product_id' => $p->id, 'supplier_id' => $supplier->id, 'cost' => 650, 'price' => 1090, 'stock' => 10]);
        Tenancy::clear();

        Artisan::call('demo:clone-shop', [
            'source_shop_id' => $source->id,
            'name' => 'Demo Variety Shop',
            'owner_email' => 'ambassador-demo-3@example.test',
            'owner_password' => 'password123',
        ]);

        $demo = Shop::withoutGlobalScopes()->where('name', 'Demo Variety Shop')->firstOrFail();

        $demoSupplier = Supplier::withoutGlobalScopes()->where('shop_id', $demo->id)->where('name', 'Sujon')->first();
        $this->assertNotNull($demoSupplier);
        $this->assertSame(0.0, (float) $demoSupplier->payable); // catalog fidelity, not a copy of real debt

        $demoProduct = Product::withoutGlobalScopes()->where('shop_id', $demo->id)->where('name', 'Boiler Suit')->first();
        $demoLine = ProductSource::withoutGlobalScopes()->where('shop_id', $demo->id)->where('product_id', $demoProduct->id)->first();
        $this->assertNotNull($demoLine);
        $this->assertSame($demoSupplier->id, $demoLine->supplier_id);
        $this->assertSame(10.0, (float) $demoLine->stock);
    }

    public function test_never_grants_branch_only_features(): void
    {
        [$source] = $this->createShopWithOwner();
        $this->grantFeature($source, 'shared_stock_pool');
        $this->grantFeature($source, 'shared_catalog');
        $this->grantFeature($source, 'suppliers');

        Artisan::call('demo:clone-shop', [
            'source_shop_id' => $source->id,
            'name' => 'Demo Shop',
            'owner_email' => 'ambassador-demo-4@example.test',
            'owner_password' => 'password123',
        ]);

        $demo = Shop::withoutGlobalScopes()->where('name', 'Demo Shop')->firstOrFail();
        $this->assertTrue($demo->hasFeature('suppliers'));
        $this->assertFalse($demo->hasFeature('shared_stock_pool'));
        $this->assertFalse($demo->hasFeature('shared_catalog'));
    }
}
