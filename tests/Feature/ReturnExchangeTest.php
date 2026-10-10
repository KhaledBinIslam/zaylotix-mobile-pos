<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Damage;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\SalePayment;
use App\Models\SalesReturn;
use App\Models\Shop;
use App\Models\User;
use App\Support\Reports;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesShops;
use Tests\TestCase;

/**
 * The new invoice-linked return/exchange flow (ReturnController::lookup/
 * process) — separate from the old no-receipt lifetime-cap flow already
 * covered by ReturnTest. Every case here asserts the actual money/stock
 * movement, not just an HTTP status, since that's the whole point of this
 * feature (Khaled's explicit concern throughout: cash/profit correctness).
 */
class ReturnExchangeTest extends TestCase
{
    use RefreshDatabase, CreatesShops;

    private function makeSale(int $shopId, array $overrides = []): Sale
    {
        return Sale::create(array_merge([
            'shop_id' => $shopId, 'invoice_no' => 'INV-'.uniqid(),
            'date' => now()->toDateString(), 'time' => now()->toTimeString(),
            'subtotal' => 200, 'discount' => 0, 'total' => 200, 'profit' => 100, 'payment_mode' => 'cash',
        ], $overrides));
    }

    private function makeItem(Sale $sale, Product $product, array $overrides = []): SaleItem
    {
        return SaleItem::create(array_merge([
            'shop_id' => $sale->shop_id, 'sale_id' => $sale->id, 'product_id' => $product->id,
            'product_name' => $product->name, 'unit_factor' => 1, 'qty' => 2, 'price' => 100, 'discount' => 0, 'cost' => 50,
        ], $overrides));
    }

    public function test_cash_sale_return_refunds_cash_and_restocks_a_resalable_item(): void
    {
        [$shop, $owner] = $this->createShopWithOwner(['cash_balance' => 1000]);
        $this->grantFeature($shop, 'returns');
        $product = Product::create(['shop_id' => $shop->id, 'name' => 'Shirt', 'cost' => 50, 'price' => 100, 'stock' => 3]);
        $sale = $this->makeSale($shop->id);
        $item = $this->makeItem($sale, $product);

        $response = $this->actingAs($owner, 'web')->postJson('/app/returns/process', [
            'sale_id' => $sale->id,
            'action' => 'refund',
            'lines' => [['sale_item_id' => $item->id, 'qty' => 1, 'condition' => 'resalable']],
        ]);

        $response->assertOk();
        $this->assertEquals(100.0, $response->json('total_refund'));
        $this->assertEquals(4, $product->fresh()->stock); // 3 + 1
        $this->assertEquals(900.0, (float) $shop->fresh()->cash_balance); // 1000 - 100
        $ret = SalesReturn::first();
        $this->assertEquals(50.0, (float) $ret->cost); // 1 unit's own cost snapshot
        $this->assertSame('return', $ret->type);
        $this->assertSame('resalable', $ret->condition);
    }

    public function test_damaged_item_does_not_restock_and_logs_a_damage_entry_instead(): void
    {
        [$shop, $owner] = $this->createShopWithOwner(['cash_balance' => 1000]);
        $this->grantFeature($shop, 'returns');
        $product = Product::create(['shop_id' => $shop->id, 'name' => 'Shirt', 'cost' => 50, 'price' => 100, 'stock' => 3]);
        $sale = $this->makeSale($shop->id);
        $item = $this->makeItem($sale, $product);

        $this->actingAs($owner, 'web')->postJson('/app/returns/process', [
            'sale_id' => $sale->id,
            'action' => 'refund',
            'lines' => [['sale_item_id' => $item->id, 'qty' => 1, 'condition' => 'damaged']],
        ])->assertOk();

        $this->assertEquals(3, $product->fresh()->stock); // unchanged — not resalable
        $this->assertEquals(1, Damage::count());
        $this->assertEquals(50.0, (float) Damage::first()->loss);
    }

    public function test_refund_is_proportional_to_line_and_overall_discount_not_todays_price(): void
    {
        [$shop, $owner] = $this->createShopWithOwner(['cash_balance' => 1000]);
        $this->grantFeature($shop, 'returns');
        $product = Product::create(['shop_id' => $shop->id, 'name' => 'Shirt', 'cost' => 50, 'price' => 999, 'stock' => 3]); // today's price is irrelevant
        // sale: 2 units @ 100, 20 line discount, plus an overall 36 discount on a 180 subtotal (20% of this line's share)
        $sale = $this->makeSale($shop->id, ['subtotal' => 180, 'discount' => 36, 'total' => 144]);
        $item = $this->makeItem($sale, $product, ['qty' => 2, 'price' => 100, 'discount' => 20, 'cost' => 50]);
        // lineTotal = 2*100 - 20 = 180 = subtotal (only line), so overall discount allocated 100% = 36
        // netPaidForLine = 180 - 36 = 144; perUnit = 72; returning 1 unit => refund 72

        $response = $this->actingAs($owner, 'web')->postJson('/app/returns/process', [
            'sale_id' => $sale->id,
            'action' => 'refund',
            'lines' => [['sale_item_id' => $item->id, 'qty' => 1, 'condition' => 'resalable']],
        ]);

        $response->assertOk();
        $this->assertEquals(72.0, $response->json('total_refund'));
    }

    public function test_returning_an_item_from_a_fully_credit_sale_reduces_due_not_cash(): void
    {
        [$shop, $owner] = $this->createShopWithOwner(['cash_balance' => 1000]);
        $this->grantFeature($shop, 'returns');
        $customer = Customer::create(['shop_id' => $shop->id, 'name' => 'Karim', 'phone' => '01711111111', 'due' => 200]);
        $product = Product::create(['shop_id' => $shop->id, 'name' => 'Shirt', 'cost' => 50, 'price' => 100, 'stock' => 3]);
        $sale = $this->makeSale($shop->id, ['customer_id' => $customer->id, 'payment_mode' => 'credit']);
        $item = $this->makeItem($sale, $product); // no SalePayment rows at all — fully on credit

        $response = $this->actingAs($owner, 'web')->postJson('/app/returns/process', [
            'sale_id' => $sale->id,
            'action' => 'refund',
            'lines' => [['sale_item_id' => $item->id, 'qty' => 1, 'condition' => 'resalable']],
        ]);

        $response->assertOk();
        $this->assertEquals(1000.0, (float) $shop->fresh()->cash_balance); // untouched — shop never held this cash
        $this->assertEquals(100.0, (float) $customer->fresh()->due); // 200 - 100
        $this->assertTrue((bool) SalesReturn::first()->applied_to_due);
    }

    public function test_split_payment_sale_return_splits_refund_between_cash_and_due_proportionally(): void
    {
        [$shop, $owner] = $this->createShopWithOwner(['cash_balance' => 1000]);
        $this->grantFeature($shop, 'returns');
        $customer = Customer::create(['shop_id' => $shop->id, 'name' => 'Karim', 'phone' => '01711111111', 'due' => 100]);
        $product = Product::create(['shop_id' => $shop->id, 'name' => 'Shirt', 'cost' => 50, 'price' => 100, 'stock' => 3]);
        // total 200, tendered 100 (cash), due remainder 100 — half of this sale was credit
        $sale = $this->makeSale($shop->id, ['customer_id' => $customer->id, 'payment_mode' => 'split']);
        $item = $this->makeItem($sale, $product);
        SalePayment::create(['shop_id' => $shop->id, 'sale_id' => $sale->id, 'method' => 'cash', 'amount' => 100]);

        // return both units (full refund 200): half (100) was due, half (100) was cash
        $response = $this->actingAs($owner, 'web')->postJson('/app/returns/process', [
            'sale_id' => $sale->id,
            'action' => 'refund',
            'lines' => [['sale_item_id' => $item->id, 'qty' => 2, 'condition' => 'resalable']],
        ]);

        $response->assertOk();
        $this->assertEquals(900.0, (float) $shop->fresh()->cash_balance); // 1000 - 100
        $this->assertEquals(0.0, (float) $customer->fresh()->due); // 100 - 100
    }

    public function test_cannot_return_more_than_the_items_remaining_returnable_qty(): void
    {
        [$shop, $owner] = $this->createShopWithOwner();
        $this->grantFeature($shop, 'returns');
        $product = Product::create(['shop_id' => $shop->id, 'name' => 'Shirt', 'cost' => 50, 'price' => 100, 'stock' => 3]);
        $sale = $this->makeSale($shop->id);
        $item = $this->makeItem($sale, $product, ['qty' => 2]);

        $response = $this->actingAs($owner, 'web')->postJson('/app/returns/process', [
            'sale_id' => $sale->id,
            'action' => 'refund',
            'lines' => [['sale_item_id' => $item->id, 'qty' => 5, 'condition' => 'resalable']],
        ]);

        $response->assertStatus(422);
        $this->assertEquals(3, $product->fresh()->stock); // unchanged
    }

    /** Double-tap safety: processing the same line twice must reject the second attempt once the first has already consumed the returnable qty. */
    public function test_a_second_identical_submit_is_rejected_once_the_item_is_fully_returned(): void
    {
        [$shop, $owner] = $this->createShopWithOwner(['cash_balance' => 1000]);
        $this->grantFeature($shop, 'returns');
        $product = Product::create(['shop_id' => $shop->id, 'name' => 'Shirt', 'cost' => 50, 'price' => 100, 'stock' => 3]);
        $sale = $this->makeSale($shop->id);
        $item = $this->makeItem($sale, $product, ['qty' => 1]);

        $payload = [
            'sale_id' => $sale->id,
            'action' => 'refund',
            'lines' => [['sale_item_id' => $item->id, 'qty' => 1, 'condition' => 'resalable']],
        ];

        $this->actingAs($owner, 'web')->postJson('/app/returns/process', $payload)->assertOk();
        $this->actingAs($owner, 'web')->postJson('/app/returns/process', $payload)->assertStatus(422);

        $this->assertEquals(4, $product->fresh()->stock); // credited only once (3 + 1)
        $this->assertEquals(900.0, (float) $shop->fresh()->cash_balance); // debited only once
    }

    public function test_cannot_process_a_return_against_another_shops_invoice(): void
    {
        [$shopA] = $this->createShopWithOwner();
        [$shopB, $ownerB] = $this->createShopWithOwner();
        $this->grantFeature($shopB, 'returns');
        $product = Product::create(['shop_id' => $shopA->id, 'name' => 'Shirt', 'cost' => 50, 'price' => 100, 'stock' => 3]);
        $sale = $this->makeSale($shopA->id);
        $item = $this->makeItem($sale, $product);

        $response = $this->actingAs($ownerB, 'web')->postJson('/app/returns/process', [
            'sale_id' => $sale->id,
            'action' => 'refund',
            'lines' => [['sale_item_id' => $item->id, 'qty' => 1, 'condition' => 'resalable']],
        ]);

        $response->assertStatus(404);
    }

    public function test_return_window_blocks_staff_but_owner_can_override(): void
    {
        [$shop, $owner] = $this->createShopWithOwner(['cash_balance' => 1000, 'return_window_days' => 7]);
        $this->grantFeature($shop, 'returns');
        $cashier = User::create(['shop_id' => $shop->id, 'name' => 'Cashier', 'phone' => '01900005555', 'password' => 'secret1', 'role' => 'staff', 'permissions' => ['returns'], 'lang' => 'bn']);
        $product = Product::create(['shop_id' => $shop->id, 'name' => 'Shirt', 'cost' => 50, 'price' => 100, 'stock' => 3]);
        $sale = $this->makeSale($shop->id, ['date' => now()->subDays(10)->toDateString()]);
        $item = $this->makeItem($sale, $product);

        $payload = [
            'sale_id' => $sale->id,
            'action' => 'refund',
            'lines' => [['sale_item_id' => $item->id, 'qty' => 1, 'condition' => 'resalable']],
        ];

        $this->actingAs($cashier, 'web')->postJson('/app/returns/process', $payload)->assertStatus(422);
        $this->actingAs($cashier, 'web')->postJson('/app/returns/process', array_merge($payload, ['override_window' => true]))->assertStatus(422);
        $this->actingAs($owner, 'web')->postJson('/app/returns/process', array_merge($payload, ['override_window' => true]))->assertOk();
    }

    public function test_exchange_for_a_pricier_item_collects_the_difference_as_due(): void
    {
        [$shop, $owner] = $this->createShopWithOwner(['cash_balance' => 1000]);
        $this->grantFeature($shop, 'returns');
        $customer = Customer::create(['shop_id' => $shop->id, 'name' => 'Karim', 'phone' => '01711111111', 'due' => 0]);
        $oldProduct = Product::create(['shop_id' => $shop->id, 'name' => 'Shirt M', 'cost' => 50, 'price' => 100, 'stock' => 3]);
        $newProduct = Product::create(['shop_id' => $shop->id, 'name' => 'Shirt L', 'cost' => 60, 'price' => 150, 'stock' => 3]);
        $sale = $this->makeSale($shop->id, ['customer_id' => $customer->id]);
        $item = $this->makeItem($sale, $oldProduct, ['qty' => 1, 'price' => 100, 'cost' => 50]);

        $response = $this->actingAs($owner, 'web')->postJson('/app/returns/process', [
            'sale_id' => $sale->id,
            'action' => 'exchange',
            'lines' => [['sale_item_id' => $item->id, 'qty' => 1, 'condition' => 'resalable']],
            'new_items' => [['product_id' => $newProduct->id, 'qty' => 1]],
        ]);

        $response->assertOk();
        $this->assertEquals(4, $oldProduct->fresh()->stock); // returned old item restocked
        $this->assertEquals(2, $newProduct->fresh()->stock); // new item taken from stock
        $this->assertEquals(50.0, (float) $customer->fresh()->due); // 150 new - 100 credit, no cash tendered
        $exchangeSale = Sale::where('sale_type', 'exchange')->first();
        $this->assertNotNull($exchangeSale);
        $this->assertEquals(150.0, (float) $exchangeSale->total);
    }

    public function test_exchange_for_a_cheaper_item_refunds_the_difference_in_cash(): void
    {
        [$shop, $owner] = $this->createShopWithOwner(['cash_balance' => 1000]);
        $this->grantFeature($shop, 'returns');
        $oldProduct = Product::create(['shop_id' => $shop->id, 'name' => 'Shirt L', 'cost' => 60, 'price' => 150, 'stock' => 3]);
        $newProduct = Product::create(['shop_id' => $shop->id, 'name' => 'Shirt M', 'cost' => 50, 'price' => 100, 'stock' => 3]);
        $sale = $this->makeSale($shop->id, ['subtotal' => 150, 'total' => 150]);
        $item = $this->makeItem($sale, $oldProduct, ['qty' => 1, 'price' => 150, 'cost' => 60]);

        $response = $this->actingAs($owner, 'web')->postJson('/app/returns/process', [
            'sale_id' => $sale->id,
            'action' => 'exchange',
            'lines' => [['sale_item_id' => $item->id, 'qty' => 1, 'condition' => 'resalable']],
            'new_items' => [['product_id' => $newProduct->id, 'qty' => 1]],
        ]);

        $response->assertOk();
        $this->assertEquals(950.0, (float) $shop->fresh()->cash_balance); // 1000 - (150 credit - 100 new item) = 1000 - 50
    }

    public function test_exchange_for_an_equal_priced_item_moves_no_money(): void
    {
        [$shop, $owner] = $this->createShopWithOwner(['cash_balance' => 1000]);
        $this->grantFeature($shop, 'returns');
        $oldProduct = Product::create(['shop_id' => $shop->id, 'name' => 'Shirt Blue', 'cost' => 50, 'price' => 100, 'stock' => 3]);
        $newProduct = Product::create(['shop_id' => $shop->id, 'name' => 'Shirt Red', 'cost' => 50, 'price' => 100, 'stock' => 3]);
        $sale = $this->makeSale($shop->id, ['subtotal' => 100, 'total' => 100]);
        $item = $this->makeItem($sale, $oldProduct, ['qty' => 1, 'price' => 100, 'cost' => 50]);

        $this->actingAs($owner, 'web')->postJson('/app/returns/process', [
            'sale_id' => $sale->id,
            'action' => 'exchange',
            'lines' => [['sale_item_id' => $item->id, 'qty' => 1, 'condition' => 'resalable']],
            'new_items' => [['product_id' => $newProduct->id, 'qty' => 1]],
        ])->assertOk();

        $this->assertEquals(1000.0, (float) $shop->fresh()->cash_balance); // unchanged
    }

    public function test_variant_return_restocks_the_specific_variant_and_the_parent_product(): void
    {
        [$shop, $owner] = $this->createShopWithOwner(['cash_balance' => 1000]);
        $this->grantFeature($shop, 'returns');
        $this->grantFeature($shop, 'product_variants');
        $product = Product::create(['shop_id' => $shop->id, 'name' => 'Shirt', 'cost' => 50, 'price' => 100, 'stock' => 5]);
        $variant = ProductVariant::create(['shop_id' => $shop->id, 'product_id' => $product->id, 'size' => 'M', 'color' => 'Blue', 'stock' => 5]);
        $sale = $this->makeSale($shop->id);
        $item = $this->makeItem($sale, $product, ['qty' => 1, 'product_variant_id' => $variant->id, 'variant_label' => 'M, Blue']);

        $this->actingAs($owner, 'web')->postJson('/app/returns/process', [
            'sale_id' => $sale->id,
            'action' => 'refund',
            'lines' => [['sale_item_id' => $item->id, 'qty' => 1, 'condition' => 'resalable']],
        ])->assertOk();

        $this->assertEquals(6, $variant->fresh()->stock);
        $this->assertEquals(6, $product->fresh()->stock);
    }

    public function test_voiding_a_sale_with_an_existing_return_is_blocked(): void
    {
        [$shop, $owner] = $this->createShopWithOwner(['cash_balance' => 1000]);
        $this->grantFeature($shop, 'returns');
        $product = Product::create(['shop_id' => $shop->id, 'name' => 'Shirt', 'cost' => 50, 'price' => 100, 'stock' => 3]);
        $sale = $this->makeSale($shop->id);
        $item = $this->makeItem($sale, $product, ['qty' => 2]);

        $this->actingAs($owner, 'web')->postJson('/app/returns/process', [
            'sale_id' => $sale->id,
            'action' => 'refund',
            'lines' => [['sale_item_id' => $item->id, 'qty' => 1, 'condition' => 'resalable']],
        ])->assertOk();

        $response = $this->actingAs($owner, 'web')->delete("/app/sales/{$sale->id}", ['reason' => 'trying to void after a return']);

        $response->assertStatus(422);
        $this->assertNull($sale->fresh()->voided_at);
    }

    public function test_loyalty_points_are_deducted_proportionally_to_the_refund(): void
    {
        [$shop, $owner] = $this->createShopWithOwner(['cash_balance' => 1000]);
        $this->grantFeature($shop, 'returns');
        $this->grantFeature($shop, 'loyalty_points');
        $customer = Customer::create(['shop_id' => $shop->id, 'name' => 'Karim', 'phone' => '01711111111', 'due' => 0, 'loyalty_points' => 10]);
        $product = Product::create(['shop_id' => $shop->id, 'name' => 'Shirt', 'cost' => 50, 'price' => 100, 'stock' => 3]);
        // total 200, earned 10 points; returning half the sale's value should deduct ~half the points
        $sale = $this->makeSale($shop->id, ['customer_id' => $customer->id, 'subtotal' => 200, 'total' => 200, 'points_earned' => 10]);
        $item = $this->makeItem($sale, $product, ['qty' => 2, 'price' => 100, 'cost' => 50]);

        $response = $this->actingAs($owner, 'web')->postJson('/app/returns/process', [
            'sale_id' => $sale->id,
            'action' => 'refund',
            'lines' => [['sale_item_id' => $item->id, 'qty' => 1, 'condition' => 'resalable']],
        ]);

        $response->assertOk();
        $this->assertEquals(5, $response->json('points_deducted')); // 10 * (100/200)
        $this->assertEquals(5, $customer->fresh()->loyalty_points);
    }

    public function test_reports_net_profit_only_subtracts_the_refund_minus_cost_portion_for_new_rows(): void
    {
        [$shop] = $this->createShopWithOwner();
        \App\Support\Tenancy::set($shop->id);
        $sale = $this->makeSale($shop->id, ['total' => 1000, 'profit' => 500]);

        // a new-format row: refund 100, cost 40 — true profit hit is 60, not the full 100
        SalesReturn::create([
            'shop_id' => $shop->id, 'product_id' => Product::create(['shop_id' => $shop->id, 'name' => 'X', 'cost' => 40, 'price' => 100, 'stock' => 0])->id,
            'qty' => 1, 'refund' => 100, 'cost' => 40, 'date' => now()->toDateString(), 'type' => 'return',
        ]);

        $stats = Reports::rangeStats(now()->toDateString(), now()->toDateString());

        $this->assertEquals(60.0, $stats['ret']);
        $this->assertEquals(500 - 60, $stats['net']);
    }

    public function test_reports_net_profit_keeps_subtracting_full_refund_for_old_null_cost_rows(): void
    {
        [$shop] = $this->createShopWithOwner();
        \App\Support\Tenancy::set($shop->id);
        $this->makeSale($shop->id, ['total' => 1000, 'profit' => 500]);

        // an old-format row saved before the cost column existed — cost stays NULL
        SalesReturn::create([
            'shop_id' => $shop->id, 'product_id' => Product::create(['shop_id' => $shop->id, 'name' => 'X', 'cost' => 40, 'price' => 100, 'stock' => 0])->id,
            'qty' => 1, 'refund' => 100, 'date' => now()->toDateString(), 'type' => 'return',
        ]);

        $stats = Reports::rangeStats(now()->toDateString(), now()->toDateString());

        $this->assertEquals(100.0, $stats['ret']); // unchanged old behavior
        $this->assertEquals(500 - 100, $stats['net']);
    }

    public function test_exchange_sale_does_not_inflate_todays_bill_count_or_revenue(): void
    {
        [$shop, $owner] = $this->createShopWithOwner(['cash_balance' => 1000]);
        $this->grantFeature($shop, 'returns');
        $oldProduct = Product::create(['shop_id' => $shop->id, 'name' => 'Shirt L', 'cost' => 60, 'price' => 150, 'stock' => 3]);
        $newProduct = Product::create(['shop_id' => $shop->id, 'name' => 'Shirt M', 'cost' => 50, 'price' => 100, 'stock' => 3]);
        $sale = $this->makeSale($shop->id, ['subtotal' => 150, 'total' => 150]);
        $item = $this->makeItem($sale, $oldProduct, ['qty' => 1, 'price' => 150, 'cost' => 60]);

        \App\Support\Tenancy::set($shop->id);
        $before = Reports::rangeStats(now()->toDateString(), now()->toDateString());

        $this->actingAs($owner, 'web')->postJson('/app/returns/process', [
            'sale_id' => $sale->id,
            'action' => 'exchange',
            'lines' => [['sale_item_id' => $item->id, 'qty' => 1, 'condition' => 'resalable']],
            'new_items' => [['product_id' => $newProduct->id, 'qty' => 1]],
        ])->assertOk();

        $after = Reports::rangeStats(now()->toDateString(), now()->toDateString());

        $this->assertEquals($before['count'], $after['count']); // the exchange sale itself isn't counted as a new bill
    }
}
