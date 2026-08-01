<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Product;
use App\Support\Reports;
use App\Support\Tenancy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesShops;
use Tests\TestCase;

/**
 * Reports::cashierCashBreakdown() — how much cash each cashier personally
 * rang up (sales), collected (due payments), and refunded (returns) during
 * a range, so it can be checked against what's physically handed over at
 * shift-end. Deliberately scoped to counter actions only — see the method's
 * own docblock for why purchases/expenses aren't included.
 */
class CashierCashBreakdownTest extends TestCase
{
    use RefreshDatabase, CreatesShops;

    public function test_cash_sales_are_attributed_to_the_cashier_who_rang_them_up(): void
    {
        [$shop, $owner] = $this->createShopWithOwner();
        $cashier = \App\Models\User::create(['shop_id' => $shop->id, 'name' => 'Cashier A', 'phone' => '01900011111', 'password' => 'secret1', 'role' => 'staff', 'permissions' => ['pos'], 'lang' => 'bn']);
        $product = Product::create(['shop_id' => $shop->id, 'name' => 'Rice', 'cost' => 50, 'price' => 100, 'stock' => 10]);

        $this->actingAs($cashier, 'web')->postJson('/app/pos/checkout', [
            'items' => [['product_id' => $product->id, 'qty' => 1]],
            'payments' => [['method' => 'cash', 'amount' => 100]],
        ])->assertOk();
        $this->actingAs($owner, 'web')->postJson('/app/pos/checkout', [
            'items' => [['product_id' => $product->id, 'qty' => 1]],
            'payments' => [['method' => 'cash', 'amount' => 100]],
        ])->assertOk();

        $today = now()->toDateString();
        $breakdown = Reports::cashierCashBreakdown($today, $today);

        $byName = collect($breakdown)->keyBy('name');
        $this->assertEquals(100.0, $byName['Cashier A']['cash_sales']);
        $this->assertEquals(100.0, $byName['Owner']['cash_sales']);
    }

    public function test_bank_and_mobile_payments_are_excluded_from_the_cash_breakdown(): void
    {
        [$shop, $owner] = $this->createShopWithOwner();
        $product = Product::create(['shop_id' => $shop->id, 'name' => 'Rice', 'cost' => 50, 'price' => 100, 'stock' => 10]);

        $this->actingAs($owner, 'web')->postJson('/app/pos/checkout', [
            'items' => [['product_id' => $product->id, 'qty' => 1]],
            'payments' => [['method' => 'bkash', 'amount' => 100]],
        ])->assertOk();

        $today = now()->toDateString();
        $breakdown = collect(Reports::cashierCashBreakdown($today, $today));
        $ownerRow = $breakdown->firstWhere('name', 'Owner');

        // the row exists (the sale itself is attributed to the owner) but
        // its cash total is zero — the bkash amount must not leak in
        $this->assertNotNull($ownerRow);
        $this->assertEquals(0.0, $ownerRow['cash_sales']);
    }

    public function test_cash_due_collections_are_attributed_to_the_collecting_user(): void
    {
        [$shop, $owner] = $this->createShopWithOwner();
        $customer = Customer::create(['shop_id' => $shop->id, 'name' => 'Regular', 'phone' => '01900022222', 'due' => 500]);

        $this->actingAs($owner, 'web')->post("/app/customers/{$customer->id}/payments", [
            'amount' => 200, 'method' => 'cash',
        ])->assertRedirect();

        $today = now()->toDateString();
        $breakdown = collect(Reports::cashierCashBreakdown($today, $today));

        $this->assertEquals(200.0, $breakdown->firstWhere('name', 'Owner')['cash_due_collected']);
    }

    public function test_cash_refunds_reduce_the_expected_cash_for_that_user(): void
    {
        [$shop, $owner] = $this->createShopWithOwner();
        $this->grantFeature($shop, 'returns');
        $product = Product::create(['shop_id' => $shop->id, 'name' => 'Soap', 'cost' => 10, 'price' => 20, 'stock' => 5]);
        \App\Models\SaleItem::create(['shop_id' => $shop->id, 'sale_id' => \App\Models\Sale::create(['shop_id' => $shop->id, 'invoice_no' => 'INV-X', 'date' => now()->toDateString(), 'time' => now()->toTimeString(), 'subtotal' => 100, 'total' => 100, 'payment_mode' => 'cash'])->id, 'product_id' => $product->id, 'product_name' => 'Soap', 'unit_factor' => 1, 'qty' => 5, 'price' => 20, 'discount' => 0, 'cost' => 10]);

        $this->actingAs($owner, 'web')->post('/app/returns', [
            'product_id' => $product->id, 'qty' => 2, 'refund' => 40,
        ])->assertRedirect();

        $today = now()->toDateString();
        $breakdown = collect(Reports::cashierCashBreakdown($today, $today));
        $ownerRow = $breakdown->firstWhere('name', 'Owner');

        $this->assertEquals(40.0, $ownerRow['cash_returns']);
        $this->assertEquals(-40.0, $ownerRow['expected_cash']); // no sales/collections that day, just the refund
    }

    public function test_breakdown_is_scoped_to_the_current_shop_only(): void
    {
        [$shopA, $ownerA] = $this->createShopWithOwner();
        [$shopB, $ownerB] = $this->createShopWithOwner();
        $productA = Product::create(['shop_id' => $shopA->id, 'name' => 'A Item', 'cost' => 10, 'price' => 20, 'stock' => 10]);
        $productB = Product::create(['shop_id' => $shopB->id, 'name' => 'B Item', 'cost' => 10, 'price' => 20, 'stock' => 10]);

        $this->actingAs($ownerA, 'web')->postJson('/app/pos/checkout', [
            'items' => [['product_id' => $productA->id, 'qty' => 1]], 'payments' => [['method' => 'cash', 'amount' => 20]],
        ])->assertOk();
        $this->actingAs($ownerB, 'web')->postJson('/app/pos/checkout', [
            'items' => [['product_id' => $productB->id, 'qty' => 1]], 'payments' => [['method' => 'cash', 'amount' => 20]],
        ])->assertOk();

        $today = now()->toDateString();
        Tenancy::set($shopA->id);
        $breakdown = collect(Reports::cashierCashBreakdown($today, $today));
        Tenancy::clear();

        $this->assertSame(1, $breakdown->count()); // only shop A's owner, not shop B's
        $this->assertEquals(20.0, $breakdown->first()['cash_sales']);
    }

    public function test_reports_page_includes_the_cashier_breakdown(): void
    {
        [$shop, $owner] = $this->createShopWithOwner();
        $this->grantFeature($shop, 'reports');
        $product = Product::create(['shop_id' => $shop->id, 'name' => 'Rice', 'cost' => 50, 'price' => 100, 'stock' => 10]);

        $this->actingAs($owner, 'web')->postJson('/app/pos/checkout', [
            'items' => [['product_id' => $product->id, 'qty' => 1]], 'payments' => [['method' => 'cash', 'amount' => 100]],
        ])->assertOk();

        $response = $this->actingAs($owner, 'web')->get('/app/reports?preset=today');

        $response->assertOk()->assertInertia(fn ($page) => $page
            ->component('App/Reports/Index')
            ->has('cashierBreakdown', 1)
        );
    }
}
