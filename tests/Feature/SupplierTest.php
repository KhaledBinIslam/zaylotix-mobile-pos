<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\Purchase;
use App\Models\Supplier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesShops;
use Tests\TestCase;

class SupplierTest extends TestCase
{
    use RefreshDatabase, CreatesShops;

    public function test_paying_a_supplier_does_not_touch_other_suppliers_payable(): void
    {
        [$shop, $owner] = $this->createShopWithOwner(['cash_balance' => 1000]);
        $this->grantFeature($shop, 'suppliers');
        $paying = Supplier::create(['shop_id' => $shop->id, 'name' => 'Alpha Traders', 'payable' => 500]);
        $bystander = Supplier::create(['shop_id' => $shop->id, 'name' => 'Beta Traders', 'payable' => 200]);

        $this->actingAs($owner, 'web')->post("/app/suppliers/{$paying->id}/payments", [
            'amount' => 200,
        ])->assertRedirect();

        $this->assertEquals(300.0, (float) $paying->fresh()->payable);
        $this->assertEquals(200.0, (float) $bystander->fresh()->payable); // unchanged
        $this->assertEquals(1000 - 200, (float) $shop->fresh()->cash_balance);
    }

    public function test_paying_more_than_the_payable_is_rejected(): void
    {
        [$shop, $owner] = $this->createShopWithOwner();
        $this->grantFeature($shop, 'suppliers');
        $supplier = Supplier::create(['shop_id' => $shop->id, 'name' => 'Alpha Traders', 'payable' => 100]);

        $this->actingAs($owner, 'web')->post("/app/suppliers/{$supplier->id}/payments", [
            'amount' => 500,
        ])->assertSessionHasErrors('amount');

        $this->assertEquals(100.0, (float) $supplier->fresh()->payable);
    }

    public function test_a_credit_purchase_linked_to_a_supplier_increases_their_payable(): void
    {
        [$shop, $owner] = $this->createShopWithOwner();
        $this->grantFeature($shop, 'suppliers');
        $this->grantFeature($shop, 'purchases');
        $supplier = Supplier::create(['shop_id' => $shop->id, 'name' => 'Alpha Traders', 'payable' => 0]);

        $this->actingAs($owner, 'web')->post('/app/purchases', [
            'supplier_id' => $supplier->id,
            'amount' => 300,
            'method' => 'credit',
        ])->assertRedirect();

        $this->assertEquals(300.0, (float) $supplier->fresh()->payable);
    }

    public function test_a_purchase_can_add_stock_in_the_same_request(): void
    {
        [$shop, $owner] = $this->createShopWithOwner(['cash_balance' => 1000]);
        $this->grantFeature($shop, 'purchases');
        $product = Product::create(['shop_id' => $shop->id, 'name' => 'Rice', 'cost' => 10, 'price' => 20, 'stock' => 50]);

        $this->actingAs($owner, 'web')->post('/app/purchases', [
            'amount' => 300,
            'method' => 'cash',
            'product_id' => $product->id,
            'qty' => 30,
            'cost' => 15,
        ])->assertRedirect();

        $fresh = $product->fresh();
        $this->assertEquals(80, $fresh->stock); // 50 + 30
        // weighted avg: (50*10 + 30*15) / 80 = 11.875
        $this->assertEqualsWithDelta(11.88, (float) $fresh->cost, 0.01);
        $this->assertEquals(1000 - 300, (float) $shop->fresh()->cash_balance);
    }

    public function test_every_supplier_with_payable_is_shown_even_past_the_settled_cap(): void
    {
        [$shop, $owner] = $this->createShopWithOwner();
        $this->grantFeature($shop, 'suppliers');
        for ($i = 0; $i < 305; $i++) {
            Supplier::create(['shop_id' => $shop->id, 'name' => "Owed Supplier {$i}", 'payable' => 100 + $i]);
        }

        $this->actingAs($owner, 'web')->get('/app/suppliers')
            ->assertOk()->assertInertia(fn ($page) => $page->has('suppliers', 305));
    }

    public function test_settled_suppliers_are_capped_at_300(): void
    {
        [$shop, $owner] = $this->createShopWithOwner();
        $this->grantFeature($shop, 'suppliers');
        for ($i = 0; $i < 310; $i++) {
            Supplier::create(['shop_id' => $shop->id, 'name' => "Settled Supplier {$i}", 'payable' => 0]);
        }

        $this->actingAs($owner, 'web')->get('/app/suppliers')
            ->assertOk()->assertInertia(fn ($page) => $page->has('suppliers', 300));
    }

    public function test_a_purchase_without_a_product_stays_money_only(): void
    {
        [$shop, $owner] = $this->createShopWithOwner(['cash_balance' => 1000]);
        $this->grantFeature($shop, 'purchases');

        $this->actingAs($owner, 'web')->post('/app/purchases', [
            'amount' => 100,
            'method' => 'cash',
            'memo' => 'Electricity bill',
        ])->assertRedirect();

        $this->assertSame(1, Purchase::count());
        $this->assertEquals(1000 - 100, (float) $shop->fresh()->cash_balance);
    }
}
