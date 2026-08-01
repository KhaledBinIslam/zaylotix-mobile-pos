<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Product;
use App\Models\Sale;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesShops;
use Tests\TestCase;

/**
 * Multi-tender checkout: a sale can now carry more than one payments[] entry
 * (e.g. part cash + part bkash), with whatever the tenders don't cover
 * automatically becoming the attached customer's due.
 */
class SplitPaymentTest extends TestCase
{
    use RefreshDatabase, CreatesShops;

    public function test_two_tender_methods_are_recorded_and_moved_to_the_right_balance_buckets(): void
    {
        [$shop, $owner] = $this->createShopWithOwner();
        $product = Product::create(['shop_id' => $shop->id, 'name' => 'Rice', 'cost' => 10, 'price' => 20, 'stock' => 100]);

        $response = $this->actingAs($owner, 'web')->postJson('/app/pos/checkout', [
            'items' => [['product_id' => $product->id, 'qty' => 10]], // total 200
            'payments' => [
                ['method' => 'cash', 'amount' => 120],
                ['method' => 'bkash', 'amount' => 80],
            ],
        ]);

        $response->assertOk();
        $sale = Sale::first();
        $this->assertSame('split', $sale->payment_mode);
        $this->assertSame(2, $sale->payments()->count());
        $this->assertEquals(1000 + 120, (float) $shop->fresh()->cash_balance);
        $this->assertEquals(0 + 80, (float) $shop->fresh()->bank_balance);
    }

    public function test_partial_tender_leaves_the_remainder_as_customer_due(): void
    {
        [$shop, $owner] = $this->createShopWithOwner();
        $product = Product::create(['shop_id' => $shop->id, 'name' => 'Rice', 'cost' => 10, 'price' => 20, 'stock' => 100]);

        $response = $this->actingAs($owner, 'web')->postJson('/app/pos/checkout', [
            'items' => [['product_id' => $product->id, 'qty' => 10]], // total 200
            'payments' => [['method' => 'cash', 'amount' => 120]],
            'customer_phone' => '01711111111',
            'customer_name' => 'Karim',
        ]);

        $response->assertOk();
        $customer = Customer::first();
        $this->assertEquals(80.0, (float) $customer->due); // 200 - 120
        $this->assertEquals(1000 + 120, (float) $shop->fresh()->cash_balance);

        $sale = Sale::first();
        $this->assertSame('split', $sale->payment_mode);
    }

    public function test_a_due_remainder_with_no_customer_is_rejected(): void
    {
        [$shop, $owner] = $this->createShopWithOwner();
        $product = Product::create(['shop_id' => $shop->id, 'name' => 'Rice', 'cost' => 10, 'price' => 20, 'stock' => 100]);

        $response = $this->actingAs($owner, 'web')->postJson('/app/pos/checkout', [
            'items' => [['product_id' => $product->id, 'qty' => 10]], // total 200
            'payments' => [['method' => 'cash', 'amount' => 120]],
            // no customer_phone — nobody to bill the remaining 80 to
        ]);

        $response->assertStatus(422);
        $this->assertSame(0, Sale::count());
        $this->assertEquals(1000.0, (float) $shop->fresh()->cash_balance); // unchanged
    }

    public function test_tendering_more_than_the_total_is_rejected(): void
    {
        [$shop, $owner] = $this->createShopWithOwner();
        $product = Product::create(['shop_id' => $shop->id, 'name' => 'Rice', 'cost' => 10, 'price' => 20, 'stock' => 100]);

        $response = $this->actingAs($owner, 'web')->postJson('/app/pos/checkout', [
            'items' => [['product_id' => $product->id, 'qty' => 1]], // total 20
            'payments' => [['method' => 'cash', 'amount' => 500]],
        ]);

        $response->assertStatus(422);
        $this->assertSame(0, Sale::count());
    }

    public function test_a_single_tender_method_still_uses_its_own_specific_payment_mode(): void
    {
        [$shop, $owner] = $this->createShopWithOwner();
        $product = Product::create(['shop_id' => $shop->id, 'name' => 'Rice', 'cost' => 10, 'price' => 20, 'stock' => 100]);

        $this->actingAs($owner, 'web')->postJson('/app/pos/checkout', [
            'items' => [['product_id' => $product->id, 'qty' => 1]],
            'payments' => [['method' => 'bkash', 'amount' => 20]],
        ])->assertOk();

        $this->assertSame('bkash', Sale::first()->payment_mode);
    }
}
