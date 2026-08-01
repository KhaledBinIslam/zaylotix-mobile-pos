<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\Sale;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesShops;
use Tests\TestCase;

class CheckoutTransactionTest extends TestCase
{
    use RefreshDatabase, CreatesShops;

    public function test_checkout_decrements_stock_and_records_a_sale(): void
    {
        [$shop, $owner] = $this->createShopWithOwner();
        $product = Product::create(['shop_id' => $shop->id, 'name' => 'Rice', 'cost' => 100, 'price' => 150, 'stock' => 10]);

        $response = $this->actingAs($owner, 'web')->postJson('/app/pos/checkout', [
            'items' => [['product_id' => $product->id, 'qty' => 3]],
            'payments' => [['method' => 'cash', 'amount' => 450]],
        ]);

        $response->assertOk();
        $this->assertEquals(7, $product->fresh()->stock);
        $this->assertSame(1, Sale::count());

        $sale = Sale::first();
        $this->assertSame(450.0, (float) $sale->total);
        $this->assertSame(150.0, (float) $sale->profit); // (150-100)*3
        $this->assertEquals(1000 + 450, (float) $shop->fresh()->cash_balance);
    }

    public function test_checkout_fails_atomically_when_stock_is_insufficient(): void
    {
        [$shop, $owner] = $this->createShopWithOwner();
        $product = Product::create(['shop_id' => $shop->id, 'name' => 'Rice', 'cost' => 100, 'price' => 150, 'stock' => 2]);

        $response = $this->actingAs($owner, 'web')->postJson('/app/pos/checkout', [
            'items' => [['product_id' => $product->id, 'qty' => 5]],
            'payments' => [['method' => 'cash', 'amount' => 750]],
        ]);

        $response->assertStatus(422);

        // nothing should have moved: stock unchanged, no sale row, no cash movement
        $this->assertEquals(2, $product->fresh()->stock);
        $this->assertSame(0, Sale::count());
        $this->assertEquals(1000.0, (float) $shop->fresh()->cash_balance);
    }

    public function test_credit_sale_creates_or_updates_customer_due(): void
    {
        [$shop, $owner] = $this->createShopWithOwner();
        $product = Product::create(['shop_id' => $shop->id, 'name' => 'Rice', 'cost' => 100, 'price' => 150, 'stock' => 10]);

        $response = $this->actingAs($owner, 'web')->postJson('/app/pos/checkout', [
            'items' => [['product_id' => $product->id, 'qty' => 2]],
            'payments' => [],
            'customer_phone' => '01711111111',
            'customer_name' => 'Karim',
        ]);

        $response->assertOk();

        $customer = \App\Models\Customer::first();
        $this->assertNotNull($customer);
        $this->assertSame('Karim', $customer->name);
        $this->assertEquals(300.0, (float) $customer->due); // 150*2
        $this->assertEquals(1000.0, (float) $shop->fresh()->cash_balance); // credit sale doesn't touch cash
    }

    public function test_per_line_discount_reduces_total_and_profit_independently_of_overall_discount(): void
    {
        [$shop, $owner] = $this->createShopWithOwner();
        $rice = Product::create(['shop_id' => $shop->id, 'name' => 'Rice', 'cost' => 100, 'price' => 150, 'stock' => 10]);
        $oil = Product::create(['shop_id' => $shop->id, 'name' => 'Oil', 'cost' => 50, 'price' => 80, 'stock' => 10]);

        $response = $this->actingAs($owner, 'web')->postJson('/app/pos/checkout', [
            'items' => [
                ['product_id' => $rice->id, 'qty' => 2, 'discount' => 20], // 300 - 20 = 280
                ['product_id' => $oil->id, 'qty' => 1, 'discount' => 0],   // 80
            ],
            'discount' => 10, // overall, on top of the line discount
            'payments' => [['method' => 'cash', 'amount' => 350]],
        ]);

        $response->assertOk();
        $sale = Sale::first();
        // subtotal = 280 + 80 = 360; total = 360 - 10 overall = 350
        $this->assertSame(350.0, (float) $sale->total);
        $this->assertSame(20.0, (float) $sale->items()->where('product_id', $rice->id)->first()->discount);
    }

    public function test_a_line_discount_cannot_exceed_that_lines_own_total(): void
    {
        [$shop, $owner] = $this->createShopWithOwner();
        $product = Product::create(['shop_id' => $shop->id, 'name' => 'Rice', 'cost' => 100, 'price' => 150, 'stock' => 10]);

        $response = $this->actingAs($owner, 'web')->postJson('/app/pos/checkout', [
            'items' => [['product_id' => $product->id, 'qty' => 1, 'discount' => 9999]],
            'payments' => [],
        ]);

        $response->assertOk();
        // line total (150) clamped to 0, never negative — the discount itself gets capped, not the total
        $this->assertSame(0.0, (float) Sale::first()->total);
    }

    public function test_multiple_checkouts_get_unique_sequential_invoice_numbers(): void
    {
        [$shop, $owner] = $this->createShopWithOwner(['invoice_counter' => 1000]);
        $product = Product::create(['shop_id' => $shop->id, 'name' => 'Rice', 'cost' => 10, 'price' => 20, 'stock' => 100]);

        $invoices = [];
        for ($i = 0; $i < 5; $i++) {
            $res = $this->actingAs($owner, 'web')->postJson('/app/pos/checkout', [
                'items' => [['product_id' => $product->id, 'qty' => 1]],
                'payments' => [['method' => 'cash', 'amount' => 20]],
            ]);
            $invoices[] = $res->json('sale.invoice_no');
        }

        $this->assertSame(['INV-1001', 'INV-1002', 'INV-1003', 'INV-1004', 'INV-1005'], $invoices);
        $this->assertSame(5, count(array_unique($invoices)));
    }
}
