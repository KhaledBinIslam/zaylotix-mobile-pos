<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\ProductSerial;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesShops;
use Tests\TestCase;

/**
 * IMEI/serial tracking is a supplementary per-unit layer on top of a
 * product's plain stock count — like batches, stock/checkout math never
 * depends on it, and a cashier who doesn't scan/type an IMEI just sells
 * normally with nothing blocked.
 */
class SerialTrackingTest extends TestCase
{
    use RefreshDatabase, CreatesShops;

    public function test_stock_in_with_imeis_registers_one_serial_per_unit(): void
    {
        [$shop, $owner] = $this->createShopWithOwner();
        $this->grantFeature($shop, 'serial_tracking');
        $product = Product::create(['shop_id' => $shop->id, 'name' => 'Phone', 'cost' => 10000, 'price' => 15000, 'stock' => 0]);

        $this->actingAs($owner, 'web')->post("/app/products/{$product->id}/stock-in", [
            'qty' => 3, 'imeis' => "111\n222\n333", 'warranty_expiry' => now()->addYear()->toDateString(),
        ])->assertRedirect();

        $this->assertEquals(3, $product->fresh()->stock);
        $this->assertSame(3, ProductSerial::count());
        $this->assertSame(['111', '222', '333'], ProductSerial::orderBy('id')->pluck('imei')->all());
    }

    public function test_imei_count_must_match_qty(): void
    {
        [$shop, $owner] = $this->createShopWithOwner();
        $this->grantFeature($shop, 'serial_tracking');
        $product = Product::create(['shop_id' => $shop->id, 'name' => 'Phone', 'cost' => 10000, 'price' => 15000, 'stock' => 0]);

        $response = $this->actingAs($owner, 'web')->post("/app/products/{$product->id}/stock-in", [
            'qty' => 3, 'imeis' => "111\n222", // only 2, for qty 3
        ]);

        $response->assertSessionHasErrors('imeis');
        $this->assertEquals(0, $product->fresh()->stock); // whole stock-in rejected, not partially applied
        $this->assertSame(0, ProductSerial::count());
    }

    public function test_stock_in_without_imeis_still_works_normally(): void
    {
        [$shop, $owner] = $this->createShopWithOwner();
        $this->grantFeature($shop, 'serial_tracking');
        $product = Product::create(['shop_id' => $shop->id, 'name' => 'Phone', 'cost' => 10000, 'price' => 15000, 'stock' => 0]);

        $this->actingAs($owner, 'web')->post("/app/products/{$product->id}/stock-in", ['qty' => 5])
            ->assertRedirect();

        $this->assertEquals(5, $product->fresh()->stock);
        $this->assertSame(0, ProductSerial::count());
    }

    public function test_checkout_with_a_matching_imei_marks_that_serial_sold(): void
    {
        [$shop, $owner] = $this->createShopWithOwner();
        $this->grantFeature($shop, 'serial_tracking');
        $product = Product::create(['shop_id' => $shop->id, 'name' => 'Phone', 'cost' => 10000, 'price' => 15000, 'stock' => 2]);
        $sold = ProductSerial::create(['shop_id' => $shop->id, 'product_id' => $product->id, 'imei' => '111', 'status' => 'in_stock']);
        $other = ProductSerial::create(['shop_id' => $shop->id, 'product_id' => $product->id, 'imei' => '222', 'status' => 'in_stock']);

        $response = $this->actingAs($owner, 'web')->postJson('/app/pos/checkout', [
            'items' => [['product_id' => $product->id, 'qty' => 1, 'imei' => '111']],
            'payments' => [['method' => 'cash', 'amount' => 15000]],
        ]);

        $response->assertOk();
        $this->assertSame('sold', $sold->fresh()->status);
        $this->assertSame('in_stock', $other->fresh()->status); // untouched
        $this->assertEquals(1, $product->fresh()->stock);
    }

    public function test_checkout_without_an_imei_still_succeeds_and_touches_no_serial(): void
    {
        [$shop, $owner] = $this->createShopWithOwner();
        $this->grantFeature($shop, 'serial_tracking');
        $product = Product::create(['shop_id' => $shop->id, 'name' => 'Phone', 'cost' => 10000, 'price' => 15000, 'stock' => 2]);
        $serial = ProductSerial::create(['shop_id' => $shop->id, 'product_id' => $product->id, 'imei' => '111', 'status' => 'in_stock']);

        $response = $this->actingAs($owner, 'web')->postJson('/app/pos/checkout', [
            'items' => [['product_id' => $product->id, 'qty' => 1]], // no imei
            'payments' => [['method' => 'cash', 'amount' => 15000]],
        ]);

        $response->assertOk();
        $this->assertSame('in_stock', $serial->fresh()->status); // untouched
        $this->assertEquals(1, $product->fresh()->stock); // stock still moved
    }

    public function test_checkout_with_an_unknown_imei_still_succeeds_without_blocking_the_sale(): void
    {
        [$shop, $owner] = $this->createShopWithOwner();
        $this->grantFeature($shop, 'serial_tracking');
        $product = Product::create(['shop_id' => $shop->id, 'name' => 'Phone', 'cost' => 10000, 'price' => 15000, 'stock' => 2]);

        $response = $this->actingAs($owner, 'web')->postJson('/app/pos/checkout', [
            'items' => [['product_id' => $product->id, 'qty' => 1, 'imei' => 'does-not-exist']],
            'payments' => [['method' => 'cash', 'amount' => 15000]],
        ]);

        $response->assertOk(); // best-effort — a typo'd/unrecognized IMEI never blocks the sale
        $this->assertEquals(1, $product->fresh()->stock);
    }

    public function test_voiding_a_sale_restores_the_sold_serial_to_in_stock(): void
    {
        [$shop, $owner] = $this->createShopWithOwner(['cash_balance' => 1000]);
        $this->grantFeature($shop, 'serial_tracking');
        $product = Product::create(['shop_id' => $shop->id, 'name' => 'Phone', 'cost' => 10000, 'price' => 15000, 'stock' => 1]);
        $serial = ProductSerial::create(['shop_id' => $shop->id, 'product_id' => $product->id, 'imei' => '111', 'status' => 'in_stock']);

        $checkout = $this->actingAs($owner, 'web')->postJson('/app/pos/checkout', [
            'items' => [['product_id' => $product->id, 'qty' => 1, 'imei' => '111']],
            'payments' => [['method' => 'cash', 'amount' => 15000]],
        ]);
        $checkout->assertOk();
        $this->assertSame('sold', $serial->fresh()->status);

        $saleId = $checkout->json('sale.id');
        $this->actingAs($owner, 'web')->delete("/app/sales/{$saleId}", ['reason' => 'Customer returned it'])
            ->assertRedirect();

        $this->assertSame('in_stock', $serial->fresh()->status);
        $this->assertEquals(1, $product->fresh()->stock);
    }

    public function test_warranty_lookup_finds_a_sold_serial_with_its_sale_and_customer(): void
    {
        [$shop, $owner] = $this->createShopWithOwner();
        $this->grantFeature($shop, 'serial_tracking');
        $product = Product::create(['shop_id' => $shop->id, 'name' => 'Phone', 'cost' => 10000, 'price' => 15000, 'stock' => 1]);
        ProductSerial::create(['shop_id' => $shop->id, 'product_id' => $product->id, 'imei' => '35678901234', 'status' => 'in_stock', 'warranty_expiry' => now()->addMonths(6)]);

        $this->actingAs($owner, 'web')->postJson('/app/pos/checkout', [
            'items' => [['product_id' => $product->id, 'qty' => 1, 'imei' => '35678901234']],
            'payments' => [['method' => 'cash', 'amount' => 15000]],
            'customer_phone' => '01711111111',
            'customer_name' => 'Karim',
        ])->assertOk();

        $response = $this->actingAs($owner, 'web')->get('/app/serials?q=35678901234');

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->where('results.0.imei', '35678901234')
            ->where('results.0.status', 'sold')
            ->where('results.0.customer', 'Karim')
            ->where('results.0.under_warranty', true)
        );
    }

    public function test_serials_are_tenant_scoped(): void
    {
        [$shopA, $ownerA] = $this->createShopWithOwner();
        $this->grantFeature($shopA, 'serial_tracking');
        [$shopB] = $this->createShopWithOwner();
        $productB = Product::create(['shop_id' => $shopB->id, 'name' => 'B', 'cost' => 1, 'price' => 2, 'stock' => 1]);
        ProductSerial::create(['shop_id' => $shopB->id, 'product_id' => $productB->id, 'imei' => 'shopB-unique-imei', 'status' => 'in_stock']);

        $response = $this->actingAs($ownerA, 'web')->get('/app/serials?q=shopB-unique-imei');

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page->has('results', 0));
    }
}
