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
 * sold"), this is bounded by "currently in stock right now" — you can't
 * physically hand back more than sits on the shelf.
 */
class SupplierReturnTest extends TestCase
{
    use RefreshDatabase, CreatesShops;

    public function test_owner_can_return_stock_to_a_supplier_and_reduce_the_payable(): void
    {
        [$shop, $owner] = $this->createShopWithOwner();
        $this->grantFeature($shop, 'suppliers');
        $supplier = Supplier::create(['shop_id' => $shop->id, 'name' => 'ABC Traders', 'payable' => 500]);
        $product = Product::create(['shop_id' => $shop->id, 'name' => 'Rice', 'cost' => 10, 'price' => 20, 'stock' => 50]);

        $response = $this->actingAs($owner, 'web')->post('/app/supplier-returns', [
            'supplier_id' => $supplier->id,
            'product_id' => $product->id,
            'qty' => 5,
            'reason' => 'Expired',
            'settlement_method' => 'payable',
            'amount' => 100,
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
            'product_id' => $product->id,
            'qty' => 5,
            'settlement_method' => 'cash',
            'amount' => 100,
        ])->assertRedirect();

        $this->assertEquals(45, $product->fresh()->stock);
        $this->assertEquals(1100.0, (float) $shop->fresh()->cash_balance); // 1000 + 100
    }

    public function test_payable_settlement_requires_a_supplier_to_be_selected(): void
    {
        [$shop, $owner] = $this->createShopWithOwner();
        $this->grantFeature($shop, 'suppliers');
        $product = Product::create(['shop_id' => $shop->id, 'name' => 'Rice', 'cost' => 10, 'price' => 20, 'stock' => 50]);

        $response = $this->actingAs($owner, 'web')->post('/app/supplier-returns', [
            'product_id' => $product->id,
            'qty' => 5,
            'settlement_method' => 'payable',
            'amount' => 100,
        ]);

        $response->assertSessionHasErrors('settlement_method');
        $this->assertEquals(50, $product->fresh()->stock); // unchanged
    }

    public function test_cannot_return_more_than_is_currently_in_stock(): void
    {
        [$shop, $owner] = $this->createShopWithOwner();
        $this->grantFeature($shop, 'suppliers');
        $product = Product::create(['shop_id' => $shop->id, 'name' => 'Rice', 'cost' => 10, 'price' => 20, 'stock' => 5]);

        $response = $this->actingAs($owner, 'web')->post('/app/supplier-returns', [
            'supplier' => 'ABC Traders',
            'product_id' => $product->id,
            'qty' => 10,
            'settlement_method' => 'cash',
            'amount' => 100,
        ]);

        $response->assertSessionHasErrors('qty');
        $this->assertEquals(5, $product->fresh()->stock); // unchanged
    }

    public function test_a_variant_product_cannot_be_returned_at_the_parent_level(): void
    {
        [$shop, $owner] = $this->createShopWithOwner();
        $this->grantFeature($shop, 'suppliers');
        $this->grantFeature($shop, 'product_variants');
        $product = Product::create(['shop_id' => $shop->id, 'name' => 'Shirt', 'cost' => 100, 'price' => 200, 'stock' => 20]);
        ProductVariant::create(['shop_id' => $shop->id, 'product_id' => $product->id, 'size' => 'M', 'stock' => 20, 'price' => 200, 'cost' => 100]);

        $response = $this->actingAs($owner, 'web')->post('/app/supplier-returns', [
            'supplier' => 'ABC Traders',
            'product_id' => $product->id,
            'qty' => 1,
            'settlement_method' => 'cash',
            'amount' => 100,
        ]);

        $response->assertSessionHasErrors('qty');
        $this->assertEquals(20, $product->fresh()->stock); // unchanged
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
            'product_id' => $productB->id,
            'qty' => 1,
            'settlement_method' => 'cash',
            'amount' => 20,
        ]);

        $response->assertSessionHasErrors('product_id');
        $this->assertEquals(50, $productB->fresh()->stock); // untouched
    }
}
