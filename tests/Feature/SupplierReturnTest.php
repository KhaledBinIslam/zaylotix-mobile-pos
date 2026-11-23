<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Shop;
use App\Models\Supplier;
use App\Models\SupplierReturn;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesShops;
use Tests\TestCase;

/**
 * The mirror-image of ReturnTest (customer -> shop): here stock leaves the
 * shop back to a supplier. Unlike a customer return (bounded by "ever
 * sold"), this is bounded by "currently in stock right now" — going below
 * zero needs the owner's explicit force confirmation, never silent.
 *
 * Multi-line (2026-11-23): one submission carries several lines, each a
 * plain product or a specific variant; one supplier_returns row per line,
 * settlement applied once with the summed amount.
 */
class SupplierReturnTest extends TestCase
{
    use RefreshDatabase, CreatesShops;

    private function createBranch(Shop $main): Shop
    {
        [$branch] = $this->createShopWithOwner(['parent_shop_id' => $main->id]);

        return $branch;
    }

    public function test_owner_can_return_stock_to_a_supplier_and_reduce_the_payable(): void
    {
        [$shop, $owner] = $this->createShopWithOwner();
        $this->grantFeature($shop, 'suppliers');
        $supplier = Supplier::create(['shop_id' => $shop->id, 'name' => 'ABC Traders', 'payable' => 500]);
        $product = Product::create(['shop_id' => $shop->id, 'name' => 'Rice', 'cost' => 10, 'price' => 20, 'stock' => 50]);

        $response = $this->actingAs($owner, 'web')->post('/app/supplier-returns', [
            'supplier_id' => $supplier->id,
            'reason' => 'Expired',
            'settlement_method' => 'payable',
            'lines' => [['product_id' => $product->id, 'qty' => 5, 'amount' => 100]],
        ]);

        $response->assertRedirect();
        $this->assertEquals(45, $product->fresh()->stock); // 50 - 5
        $this->assertEquals(400.0, (float) $supplier->fresh()->payable); // 500 - 100
        $this->assertSame(1, SupplierReturn::count());
    }

    public function test_cash_settlement_increases_shop_cash_balance_instead_of_touching_payable(): void
    {
        [$shop, $owner] = $this->createShopWithOwner(['cash_balance' => 1000]);
        $this->grantFeature($shop, 'suppliers');
        $product = Product::create(['shop_id' => $shop->id, 'name' => 'Rice', 'cost' => 10, 'price' => 20, 'stock' => 50]);

        $this->actingAs($owner, 'web')->post('/app/supplier-returns', [
            'supplier' => 'Walk-in supplier',
            'settlement_method' => 'cash',
            'lines' => [['product_id' => $product->id, 'qty' => 5, 'amount' => 100]],
        ])->assertRedirect();

        $this->assertEquals(45, $product->fresh()->stock);
        $this->assertEquals(1100.0, (float) $shop->fresh()->cash_balance); // 1000 + 100
    }

    /** Phase 4.7 — see docs/FIX_PLAN.md. Same fix as the purchase form: a free-text supplier name can now settle against payable too. */
    public function test_a_free_text_supplier_name_can_now_settle_against_payable(): void
    {
        [$shop, $owner] = $this->createShopWithOwner();
        $this->grantFeature($shop, 'suppliers');
        $product = Product::create(['shop_id' => $shop->id, 'name' => 'Rice', 'cost' => 10, 'price' => 20, 'stock' => 50]);

        $response = $this->actingAs($owner, 'web')->post('/app/supplier-returns', [
            'supplier' => 'নতুন সাপ্লায়ার',
            'settlement_method' => 'payable',
            'lines' => [['product_id' => $product->id, 'qty' => 5, 'amount' => 100]],
        ]);

        $response->assertRedirect();
        $supplier = Supplier::where('name', 'নতুন সাপ্লায়ার')->first();
        $this->assertNotNull($supplier);
        $this->assertEquals(-100.0, (float) $supplier->payable); // new supplier, payable starts at 0, return reduces it
    }

    public function test_payable_settlement_requires_a_supplier_to_be_selected(): void
    {
        [$shop, $owner] = $this->createShopWithOwner();
        $this->grantFeature($shop, 'suppliers');
        $product = Product::create(['shop_id' => $shop->id, 'name' => 'Rice', 'cost' => 10, 'price' => 20, 'stock' => 50]);

        $response = $this->actingAs($owner, 'web')->post('/app/supplier-returns', [
            'settlement_method' => 'payable',
            'lines' => [['product_id' => $product->id, 'qty' => 5, 'amount' => 100]],
        ]);

        $response->assertSessionHasErrors('settlement_method');
        $this->assertEquals(50, $product->fresh()->stock); // unchanged
    }

    public function test_returning_more_than_stock_is_refused_without_force(): void
    {
        [$shop, $owner] = $this->createShopWithOwner();
        $this->grantFeature($shop, 'suppliers');
        $product = Product::create(['shop_id' => $shop->id, 'name' => 'Rice', 'cost' => 10, 'price' => 20, 'stock' => 5]);

        $response = $this->actingAs($owner, 'web')->post('/app/supplier-returns', [
            'supplier' => 'ABC Traders',
            'settlement_method' => 'cash',
            'lines' => [['product_id' => $product->id, 'qty' => 10, 'amount' => 100]],
        ]);

        $response->assertSessionHasErrors('force');
        $this->assertEquals(5, $product->fresh()->stock); // unchanged
        $this->assertSame(0, SupplierReturn::count());
    }

    public function test_force_lets_the_owner_knowingly_take_stock_below_zero(): void
    {
        [$shop, $owner] = $this->createShopWithOwner(['cash_balance' => 0]);
        $this->grantFeature($shop, 'suppliers');
        $product = Product::create(['shop_id' => $shop->id, 'name' => 'Rice', 'cost' => 10, 'price' => 20, 'stock' => 5]);

        $response = $this->actingAs($owner, 'web')->post('/app/supplier-returns', [
            'supplier' => 'ABC Traders',
            'settlement_method' => 'cash',
            'force' => true,
            'lines' => [['product_id' => $product->id, 'qty' => 10, 'amount' => 100]],
        ]);

        $response->assertRedirect()->assertSessionHasNoErrors();
        $this->assertEquals(-5, $product->fresh()->stock); // 5 - 10, confirmed
        $this->assertSame(1, SupplierReturn::count());
    }

    /** A variant product's parent-level stock is the live sum of its variants' — the specific size/color must be named. */
    public function test_a_variant_product_cannot_be_returned_without_naming_the_variant(): void
    {
        [$shop, $owner] = $this->createShopWithOwner();
        $this->grantFeature($shop, 'suppliers');
        $this->grantFeature($shop, 'product_variants');
        $product = Product::create(['shop_id' => $shop->id, 'name' => 'Shirt', 'cost' => 100, 'price' => 200, 'stock' => 20]);
        ProductVariant::create(['shop_id' => $shop->id, 'product_id' => $product->id, 'size' => 'M', 'stock' => 20, 'price' => 200, 'cost' => 100]);

        $response = $this->actingAs($owner, 'web')->post('/app/supplier-returns', [
            'supplier' => 'ABC Traders',
            'settlement_method' => 'cash',
            'lines' => [['product_id' => $product->id, 'qty' => 1, 'amount' => 100]],
        ]);

        $response->assertSessionHasErrors('lines.0.product_variant_id');
        $this->assertEquals(20, $product->fresh()->stock); // unchanged
    }

    public function test_a_multi_line_return_with_a_plain_product_and_a_variant_line(): void
    {
        [$shop, $owner] = $this->createShopWithOwner();
        $this->grantFeature($shop, 'suppliers');
        $this->grantFeature($shop, 'product_variants');
        $supplier = Supplier::create(['shop_id' => $shop->id, 'name' => 'ABC Traders', 'payable' => 1000]);
        $rice = Product::create(['shop_id' => $shop->id, 'name' => 'Rice', 'cost' => 10, 'price' => 20, 'stock' => 50]);
        $shirt = Product::create(['shop_id' => $shop->id, 'name' => 'Shirt', 'cost' => 100, 'price' => 200, 'stock' => 20]);
        $variant = ProductVariant::create(['shop_id' => $shop->id, 'product_id' => $shirt->id, 'size' => 'M', 'stock' => 20, 'price' => 200, 'cost' => 100]);

        $response = $this->actingAs($owner, 'web')->post('/app/supplier-returns', [
            'supplier_id' => $supplier->id,
            'settlement_method' => 'payable',
            'lines' => [
                ['product_id' => $rice->id, 'qty' => 5, 'amount' => 50],
                ['product_id' => $shirt->id, 'product_variant_id' => $variant->id, 'qty' => 3, 'amount' => 300],
            ],
        ]);

        $response->assertRedirect()->assertSessionHasNoErrors();
        $this->assertEquals(45, $rice->fresh()->stock); // 50 - 5
        $this->assertEquals(17, $variant->fresh()->stock); // 20 - 3
        $this->assertEquals(17, $shirt->fresh()->stock); // parent mirrors the variant sum
        $this->assertEquals(650.0, (float) $supplier->fresh()->payable); // 1000 - (50 + 300)
        $this->assertSame(2, SupplierReturn::count());
        $this->assertSame(1, SupplierReturn::whereNotNull('product_variant_id')->count());
    }

    /** shared_stock_pool: a variant returned at the MAIN shop must drop the branch's matching variant and product by the same qty — pool stays equal. */
    public function test_a_pooled_variant_return_at_the_main_shop_drops_the_branch_too(): void
    {
        [$main, $mainOwner] = $this->createShopWithOwner();
        $this->grantFeature($main, 'suppliers');
        $this->grantFeature($main, 'product_variants');
        $this->grantFeature($main, 'shared_stock_pool');
        $branch = $this->createBranch($main);

        $mainProduct = Product::create(['shop_id' => $main->id, 'name' => 'Shoe', 'cost' => 500, 'price' => 1000, 'stock' => 10]);
        $branchProduct = Product::create(['shop_id' => $branch->id, 'master_product_id' => $mainProduct->id, 'name' => 'Shoe', 'cost' => 500, 'price' => 1000, 'stock' => 10]);
        $mainVariant = ProductVariant::create(['shop_id' => $main->id, 'product_id' => $mainProduct->id, 'size' => '40', 'color' => 'Black', 'barcode' => 'SHOE-40-B', 'stock' => 10, 'cost' => 500, 'price' => 1000]);
        // mirrored into the branch by the created() hook, barcode and all
        $branchVariant = ProductVariant::withoutGlobalScopes()->where('product_id', $branchProduct->id)->firstOrFail();

        $response = $this->actingAs($mainOwner, 'web')->post('/app/supplier-returns', [
            'supplier' => 'ABC Traders',
            'settlement_method' => 'cash',
            'lines' => [['product_id' => $mainProduct->id, 'product_variant_id' => $mainVariant->id, 'qty' => 4, 'amount' => 2000]],
        ]);

        $response->assertRedirect()->assertSessionHasNoErrors();
        $this->assertEquals(6, (float) $mainVariant->fresh()->stock); // 10 - 4
        $this->assertEquals(6, (float) $mainProduct->fresh()->stock);
        $this->assertEquals(6, (float) ProductVariant::withoutGlobalScopes()->whereKey($branchVariant->id)->first()->stock, 'pooled branch variant must mirror the return');
        $this->assertEquals(6, (float) Product::withoutGlobalScopes()->whereKey($branchProduct->id)->first()->stock, 'pooled branch product must mirror the return');
    }

    public function test_supplier_return_is_scoped_to_the_shop_that_created_it(): void
    {
        [$shopA, $ownerA] = $this->createShopWithOwner();
        [$shopB] = $this->createShopWithOwner();
        $this->grantFeature($shopA, 'suppliers');
        $productB = Product::create(['shop_id' => $shopB->id, 'name' => 'Rice', 'cost' => 10, 'price' => 20, 'stock' => 50]);

        // shop A's owner must not be able to reference shop B's product id
        $response = $this->actingAs($ownerA, 'web')->post('/app/supplier-returns', [
            'supplier' => 'ABC Traders',
            'settlement_method' => 'cash',
            'lines' => [['product_id' => $productB->id, 'qty' => 1, 'amount' => 20]],
        ]);

        $response->assertSessionHasErrors('lines.0.product_id');
        $this->assertEquals(50, $productB->fresh()->stock); // untouched
    }
}
