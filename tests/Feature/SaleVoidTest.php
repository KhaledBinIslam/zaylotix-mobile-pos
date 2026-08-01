<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Product;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\SalePayment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesShops;
use Tests\TestCase;

class SaleVoidTest extends TestCase
{
    use RefreshDatabase, CreatesShops;

    private function makeSale(int $shopId, int $productId, array $overrides = []): Sale
    {
        return Sale::create(array_merge([
            'shop_id' => $shopId, 'invoice_no' => 'INV-'.uniqid(),
            'date' => now()->toDateString(), 'time' => now()->toTimeString(),
            'subtotal' => 200, 'total' => 200, 'profit' => 100, 'payment_mode' => 'cash',
        ], $overrides));
    }

    public function test_owner_can_void_a_sale_and_stock_balance_are_reversed(): void
    {
        [$shop, $owner] = $this->createShopWithOwner(['cash_balance' => 1000]);
        $product = Product::create(['shop_id' => $shop->id, 'name' => 'Rice', 'cost' => 100, 'price' => 200, 'stock' => 5]);
        $sale = $this->makeSale($shop->id, $product->id);
        SaleItem::create([
            'shop_id' => $shop->id, 'sale_id' => $sale->id, 'product_id' => $product->id,
            'product_name' => 'Rice', 'unit_factor' => 1, 'qty' => 1, 'price' => 200, 'cost' => 100,
        ]);
        $product->decrement('stock', 1); // stock now 4, matching what the sale did

        $response = $this->actingAs($owner, 'web')->delete("/app/sales/{$sale->id}", [
            'reason' => 'Customer changed their mind',
        ]);

        $response->assertRedirect();
        $this->assertEquals(5, $product->fresh()->stock); // given back
        $this->assertEquals(1000 - 200, (float) $shop->fresh()->cash_balance); // reversed

        $sale->refresh();
        $this->assertNotNull($sale->voided_at);
        $this->assertSame('Customer changed their mind', $sale->voided_reason);
        $this->assertSame($owner->id, $sale->voided_by);
    }

    public function test_a_voided_sale_is_excluded_from_the_default_sale_query(): void
    {
        [$shop, $owner] = $this->createShopWithOwner();
        $product = Product::create(['shop_id' => $shop->id, 'name' => 'Rice', 'cost' => 100, 'price' => 200, 'stock' => 5]);
        $sale = $this->makeSale($shop->id, $product->id);

        $this->actingAs($owner, 'web')->delete("/app/sales/{$sale->id}", ['reason' => 'mistake entry'])
            ->assertRedirect();

        $this->assertSame(0, Sale::count()); // default scope hides voided sales
        $this->assertSame(1, Sale::withVoided()->count()); // but the row still exists
    }

    public function test_voided_sale_is_still_viewable_on_its_show_page(): void
    {
        [$shop, $owner] = $this->createShopWithOwner();
        $product = Product::create(['shop_id' => $shop->id, 'name' => 'Rice', 'cost' => 100, 'price' => 200, 'stock' => 5]);
        $sale = $this->makeSale($shop->id, $product->id);
        $this->actingAs($owner, 'web')->delete("/app/sales/{$sale->id}", ['reason' => 'mistake entry']);

        $this->actingAs($owner, 'web')->get("/app/sales/{$sale->id}")->assertOk();
    }

    public function test_cashier_cannot_void_a_sale_even_with_sales_history_permission(): void
    {
        [$shop, $owner] = $this->createShopWithOwner();
        $cashier = User::create([
            'shop_id' => $shop->id, 'name' => 'Cashier', 'phone' => '01900005555',
            'password' => 'secret1', 'role' => 'staff', 'permissions' => ['sales_history'], 'lang' => 'bn',
        ]);
        $product = Product::create(['shop_id' => $shop->id, 'name' => 'Rice', 'cost' => 100, 'price' => 200, 'stock' => 5]);
        $sale = $this->makeSale($shop->id, $product->id);

        $this->actingAs($cashier, 'web')->delete("/app/sales/{$sale->id}", ['reason' => 'nope'])
            ->assertForbidden();

        $this->assertNull($sale->fresh()->voided_at);
    }

    public function test_voiding_a_split_payment_sale_reverses_every_tender_and_the_due_remainder(): void
    {
        [$shop, $owner] = $this->createShopWithOwner(['cash_balance' => 1000, 'bank_balance' => 500]);
        $product = Product::create(['shop_id' => $shop->id, 'name' => 'Rice', 'cost' => 100, 'price' => 200, 'stock' => 5]);
        $customer = Customer::create(['shop_id' => $shop->id, 'name' => 'Karim', 'phone' => '01711111111', 'due' => 0, 'total_spent' => 200, 'visits' => 1]);
        $sale = $this->makeSale($shop->id, $product->id, ['customer_id' => $customer->id, 'total' => 200, 'payment_mode' => 'split']);
        SaleItem::create([
            'shop_id' => $shop->id, 'sale_id' => $sale->id, 'product_id' => $product->id,
            'product_name' => 'Rice', 'unit_factor' => 1, 'qty' => 1, 'price' => 200, 'cost' => 100,
        ]);
        SalePayment::create(['shop_id' => $shop->id, 'sale_id' => $sale->id, 'method' => 'cash', 'amount' => 100]);
        SalePayment::create(['shop_id' => $shop->id, 'sale_id' => $sale->id, 'method' => 'bkash', 'amount' => 50]);
        $customer->update(['due' => 50]); // the 50 due remainder (200 - 100 - 50)
        $product->decrement('stock', 1);

        $this->actingAs($owner, 'web')->delete("/app/sales/{$sale->id}", ['reason' => 'Refunded by owner'])
            ->assertRedirect();

        $this->assertEquals(5, $product->fresh()->stock);
        $this->assertEquals(1000 - 100, (float) $shop->fresh()->cash_balance);
        $this->assertEquals(500 - 50, (float) $shop->fresh()->bank_balance);
        $this->assertEquals(0.0, (float) $customer->fresh()->due); // 50 - 50
    }
}
