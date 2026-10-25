<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\OnlineOrder;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Sale;
use App\Models\SalesChannel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Tests\Concerns\CreatesShops;
use Tests\TestCase;

/**
 * "Online Business" module, part 2 — online orders on shared stock (see
 * docs/FIX_PLAN.md's Phase 32 design note). Core decision under test: an
 * order's Sale/stock/payments are real immediately, but excluded from
 * every report (Sale's own global scope) until Delivered.
 */
class OnlineOrderTest extends TestCase
{
    use RefreshDatabase, CreatesShops;

    private function setupShop(): array
    {
        [$shop, $owner] = $this->createShopWithOwner();
        $this->grantFeature($shop, 'online_business');
        $channel = SalesChannel::create(['shop_id' => $shop->id, 'name' => 'Page A', 'platform_type' => 'facebook', 'order_prefix' => 'FB-']);

        return [$shop, $owner, $channel];
    }

    public function test_placing_an_order_decrements_stock_immediately(): void
    {
        [$shop, $owner, $channel] = $this->setupShop();
        $product = Product::create(['shop_id' => $shop->id, 'name' => 'Rice', 'cost' => 50, 'price' => 100, 'stock' => 10]);

        $response = $this->actingAs($owner, 'web')->post(route('app.onlineOrders.store'), [
            'channel_id' => $channel->id, 'customer_phone' => '01811111111', 'customer_name' => 'Karim',
            'items' => [['product_id' => $product->id, 'qty' => 3]],
            'cod_amount' => 300,
        ]);

        $response->assertRedirect();
        $this->assertEquals(7, (float) $product->fresh()->stock);

        $order = OnlineOrder::first();
        $this->assertSame('pending', $order->status);
        $this->assertStringStartsWith('FB-', $order->order_number);
        $this->assertStringStartsWith('OL-', $order->sale->invoice_no);
        $this->assertEquals(300, (float) $order->sale->total);
    }

    public function test_a_pending_order_is_invisible_to_reports_but_cod_doesnt_touch_balances(): void
    {
        [$shop, $owner, $channel] = $this->setupShop();
        $product = Product::create(['shop_id' => $shop->id, 'name' => 'Rice', 'cost' => 50, 'price' => 100, 'stock' => 10]);

        $this->actingAs($owner, 'web')->post(route('app.onlineOrders.store'), [
            'channel_id' => $channel->id, 'customer_phone' => '01811111111',
            'items' => [['product_id' => $product->id, 'qty' => 3]],
            'cod_amount' => 300,
        ])->assertRedirect();

        // the default Sale scope excludes it — proves zero changes needed
        // anywhere in Reports.php
        $this->assertSame(0, Sale::count());
        $this->assertSame(1, Sale::withVoided()->count());

        // COD is with the courier, not the shop, until settlement
        $this->assertEquals(1000, (float) $shop->fresh()->cash_balance); // untouched from its seeded default
        $this->assertEquals(0, (float) $shop->fresh()->bank_balance);
    }

    public function test_delivering_makes_it_visible_and_credits_loyalty(): void
    {
        [$shop, $owner, $channel] = $this->setupShop();
        $this->grantFeature($shop, 'loyalty_points');
        $shop->update(['loyalty_earn_rate' => 5]);
        $product = Product::create(['shop_id' => $shop->id, 'name' => 'Rice', 'cost' => 50, 'price' => 100, 'stock' => 10]);
        $customer = Customer::create(['shop_id' => $shop->id, 'name' => 'Karim', 'phone' => '01811111111']);

        $this->actingAs($owner, 'web')->post(route('app.onlineOrders.store'), [
            'channel_id' => $channel->id, 'customer_phone' => '01811111111',
            'items' => [['product_id' => $product->id, 'qty' => 2]],
            'cod_amount' => 200,
        ])->assertRedirect();
        $order = OnlineOrder::first();

        foreach (['confirmed', 'packed', 'handed_to_courier', 'in_transit'] as $status) {
            $this->actingAs($owner, 'web')->patch(route('app.onlineOrders.transition', $order->id), ['status' => $status])->assertRedirect();
        }
        $this->actingAs($owner, 'web')->post(route('app.onlineOrders.deliver', $order->id))->assertRedirect();

        $this->assertSame(1, Sale::count()); // now visible
        $order->refresh();
        $this->assertSame('delivered', $order->status);
        $this->assertNotNull($order->delivered_at);
        $this->assertEquals(10, (int) $customer->refresh()->loyalty_points); // floor(200/100*5)
        $this->assertEquals(200, (float) $customer->refresh()->total_spent);
        $this->assertSame(1, $customer->refresh()->visits);
    }

    public function test_cancelling_a_pending_order_restores_stock_and_voids_the_sale(): void
    {
        [$shop, $owner, $channel] = $this->setupShop();
        $product = Product::create(['shop_id' => $shop->id, 'name' => 'Rice', 'cost' => 50, 'price' => 100, 'stock' => 10]);

        $this->actingAs($owner, 'web')->post(route('app.onlineOrders.store'), [
            'channel_id' => $channel->id, 'customer_phone' => '01811111111',
            'items' => [['product_id' => $product->id, 'qty' => 3]],
            'cod_amount' => 300,
        ])->assertRedirect();
        $order = OnlineOrder::first();
        $this->assertEquals(7, (float) $product->fresh()->stock);

        $this->actingAs($owner, 'web')->post(route('app.onlineOrders.cancel', $order->id), ['reason' => 'customer changed mind'])->assertRedirect();

        $this->assertEquals(10, (float) $product->fresh()->stock);
        $order->refresh();
        $this->assertSame('cancelled', $order->status);
        $this->assertTrue($order->sale->isVoided());
    }

    public function test_cancelling_never_touches_bank_balance_for_an_unsettled_cod_payment(): void
    {
        [$shop, $owner, $channel] = $this->setupShop();
        $product = Product::create(['shop_id' => $shop->id, 'name' => 'Rice', 'cost' => 50, 'price' => 100, 'stock' => 10]);

        $this->actingAs($owner, 'web')->post(route('app.onlineOrders.store'), [
            'channel_id' => $channel->id, 'customer_phone' => '01811111111',
            'items' => [['product_id' => $product->id, 'qty' => 1]],
            'cod_amount' => 100,
        ])->assertRedirect();
        $order = OnlineOrder::first();

        $this->actingAs($owner, 'web')->post(route('app.onlineOrders.cancel', $order->id), ['reason' => 'test'])->assertRedirect();

        $this->assertEquals(0, (float) $shop->fresh()->bank_balance);
    }

    public function test_a_prepaid_portion_bumps_bank_balance_immediately(): void
    {
        [$shop, $owner, $channel] = $this->setupShop();
        $product = Product::create(['shop_id' => $shop->id, 'name' => 'Rice', 'cost' => 50, 'price' => 100, 'stock' => 10]);

        $this->actingAs($owner, 'web')->post(route('app.onlineOrders.store'), [
            'channel_id' => $channel->id, 'customer_phone' => '01811111111',
            'items' => [['product_id' => $product->id, 'qty' => 1]],
            'paid_amount' => 100, 'paid_method' => 'bkash',
        ])->assertRedirect();

        $this->assertEquals(100, (float) $shop->fresh()->bank_balance);
    }

    public function test_cannot_deliver_a_pending_order_directly(): void
    {
        [$shop, $owner, $channel] = $this->setupShop();
        $product = Product::create(['shop_id' => $shop->id, 'name' => 'Rice', 'cost' => 50, 'price' => 100, 'stock' => 10]);

        $this->actingAs($owner, 'web')->post(route('app.onlineOrders.store'), [
            'channel_id' => $channel->id, 'customer_phone' => '01811111111',
            'items' => [['product_id' => $product->id, 'qty' => 1]], 'cod_amount' => 100,
        ])->assertRedirect();
        $order = OnlineOrder::first();

        $this->actingAs($owner, 'web')->post(route('app.onlineOrders.deliver', $order->id))->assertStatus(422);
    }

    public function test_full_lifecycle_through_to_delivered(): void
    {
        [$shop, $owner, $channel] = $this->setupShop();
        $product = Product::create(['shop_id' => $shop->id, 'name' => 'Rice', 'cost' => 50, 'price' => 100, 'stock' => 10]);

        $this->actingAs($owner, 'web')->post(route('app.onlineOrders.store'), [
            'channel_id' => $channel->id, 'customer_phone' => '01811111111',
            'items' => [['product_id' => $product->id, 'qty' => 1]], 'cod_amount' => 100,
        ])->assertRedirect();
        $order = OnlineOrder::first();

        foreach (['confirmed', 'packed', 'handed_to_courier', 'in_transit'] as $status) {
            $this->actingAs($owner, 'web')->patch(route('app.onlineOrders.transition', $order->id), ['status' => $status])->assertRedirect();
            $this->assertSame($status, $order->refresh()->status);
        }

        $this->actingAs($owner, 'web')->post(route('app.onlineOrders.deliver', $order->id))->assertRedirect();
        $this->assertSame('delivered', $order->refresh()->status);
    }

    public function test_skipping_a_lifecycle_stage_is_rejected(): void
    {
        [$shop, $owner, $channel] = $this->setupShop();
        $product = Product::create(['shop_id' => $shop->id, 'name' => 'Rice', 'cost' => 50, 'price' => 100, 'stock' => 10]);

        $this->actingAs($owner, 'web')->post(route('app.onlineOrders.store'), [
            'channel_id' => $channel->id, 'customer_phone' => '01811111111',
            'items' => [['product_id' => $product->id, 'qty' => 1]], 'cod_amount' => 100,
        ])->assertRedirect();
        $order = OnlineOrder::first();

        $this->actingAs($owner, 'web')->patch(route('app.onlineOrders.transition', $order->id), ['status' => 'in_transit'])->assertStatus(422);
    }

    public function test_full_return_after_delivery_restores_stock(): void
    {
        [$shop, $owner, $channel] = $this->setupShop();
        $product = Product::create(['shop_id' => $shop->id, 'name' => 'Rice', 'cost' => 50, 'price' => 100, 'stock' => 10]);

        $this->actingAs($owner, 'web')->post(route('app.onlineOrders.store'), [
            'channel_id' => $channel->id, 'customer_phone' => '01811111111',
            'items' => [['product_id' => $product->id, 'qty' => 2]], 'cod_amount' => 200,
        ])->assertRedirect();
        $order = OnlineOrder::first();
        foreach (['confirmed', 'packed', 'handed_to_courier', 'in_transit'] as $status) {
            $this->actingAs($owner, 'web')->patch(route('app.onlineOrders.transition', $order->id), ['status' => $status])->assertRedirect();
        }
        $this->actingAs($owner, 'web')->post(route('app.onlineOrders.deliver', $order->id))->assertRedirect();
        $this->assertEquals(8, (float) $product->fresh()->stock);

        $this->actingAs($owner, 'web')->patch(route('app.onlineOrders.transition', $order->id), ['status' => 'returning'])->assertRedirect();
        $this->actingAs($owner, 'web')->post(route('app.onlineOrders.return', $order->id), ['reason' => 'size issue'])->assertRedirect();

        $this->assertEquals(10, (float) $product->fresh()->stock);
        $order->refresh();
        $this->assertSame('returned', $order->status);
        $this->assertTrue($order->sale->isVoided());
    }

    public function test_variant_stock_decrements_and_restores_correctly(): void
    {
        [$shop, $owner, $channel] = $this->setupShop();
        $product = Product::create(['shop_id' => $shop->id, 'name' => 'Shirt', 'cost' => 100, 'price' => 200, 'stock' => 0]);
        $variant = ProductVariant::create(['shop_id' => $shop->id, 'product_id' => $product->id, 'size' => 'M', 'color' => 'Blue', 'stock' => 5]);

        $this->actingAs($owner, 'web')->post(route('app.onlineOrders.store'), [
            'channel_id' => $channel->id, 'customer_phone' => '01811111111',
            'items' => [['product_id' => $product->id, 'product_variant_id' => $variant->id, 'qty' => 2]],
            'cod_amount' => 400,
        ])->assertRedirect();

        $this->assertEquals(3, (float) $variant->fresh()->stock);

        $order = OnlineOrder::first();
        $this->actingAs($owner, 'web')->post(route('app.onlineOrders.cancel', $order->id), ['reason' => 'test'])->assertRedirect();
        $this->assertEquals(5, (float) $variant->fresh()->stock);
    }

    public function test_insufficient_stock_is_rejected_and_nothing_is_created(): void
    {
        [$shop, $owner, $channel] = $this->setupShop();
        $product = Product::create(['shop_id' => $shop->id, 'name' => 'Rice', 'cost' => 50, 'price' => 100, 'stock' => 2]);

        $this->actingAs($owner, 'web')->post(route('app.onlineOrders.store'), [
            'channel_id' => $channel->id, 'customer_phone' => '01811111111',
            'items' => [['product_id' => $product->id, 'qty' => 5]], 'cod_amount' => 500,
        ])->assertStatus(422);

        $this->assertEquals(2, (float) $product->fresh()->stock);
        $this->assertSame(0, OnlineOrder::count());
    }

    public function test_online_order_creation_is_blocked_without_the_feature(): void
    {
        [$shop, $owner] = $this->createShopWithOwner(); // feature not granted
        $channel = SalesChannel::create(['shop_id' => $shop->id, 'name' => 'Page A', 'platform_type' => 'facebook', 'order_prefix' => 'FB-']);
        $product = Product::create(['shop_id' => $shop->id, 'name' => 'Rice', 'cost' => 50, 'price' => 100, 'stock' => 10]);

        $this->actingAs($owner, 'web')->post(route('app.onlineOrders.store'), [
            'channel_id' => $channel->id, 'customer_phone' => '01811111111',
            'items' => [['product_id' => $product->id, 'qty' => 1]], 'cod_amount' => 100,
        ])->assertStatus(403);
    }

    public function test_the_salesbytype_breakdown_gains_an_online_bucket_once_delivered(): void
    {
        [$shop, $owner, $channel] = $this->setupShop();
        $this->grantFeature($shop, 'reports');
        $this->grantFeature($shop, 'wholesale_pricing'); // salesByType is gated behind this, see ReportController
        $product = Product::create(['shop_id' => $shop->id, 'name' => 'Rice', 'cost' => 50, 'price' => 100, 'stock' => 10]);

        $this->actingAs($owner, 'web')->post(route('app.onlineOrders.store'), [
            'channel_id' => $channel->id, 'customer_phone' => '01811111111',
            'items' => [['product_id' => $product->id, 'qty' => 1]], 'cod_amount' => 100,
        ])->assertRedirect();
        $order = OnlineOrder::first();

        foreach (['confirmed', 'packed', 'handed_to_courier', 'in_transit'] as $status) {
            $this->actingAs($owner, 'web')->patch(route('app.onlineOrders.transition', $order->id), ['status' => $status])->assertRedirect();
        }
        $this->actingAs($owner, 'web')->post(route('app.onlineOrders.deliver', $order->id))->assertRedirect();

        $response = $this->actingAs($owner, 'web')->get('/app/reports?preset=today');
        $response->assertOk()->assertInertia(fn ($page) => $page->where('salesByType.online.total', 100));
    }

    public function test_customer_risk_endpoint_reports_prior_cancellations(): void
    {
        [$shop, $owner, $channel] = $this->setupShop();
        $product = Product::create(['shop_id' => $shop->id, 'name' => 'Rice', 'cost' => 50, 'price' => 100, 'stock' => 10]);

        $this->actingAs($owner, 'web')->post(route('app.onlineOrders.store'), [
            'channel_id' => $channel->id, 'customer_phone' => '01811111111',
            'items' => [['product_id' => $product->id, 'qty' => 1]], 'cod_amount' => 100,
        ])->assertRedirect();
        $order = OnlineOrder::first();
        $this->actingAs($owner, 'web')->post(route('app.onlineOrders.cancel', $order->id), ['reason' => 'test'])->assertRedirect();

        $response = $this->actingAs($owner, 'web')->getJson(route('app.onlineOrders.customerRisk').'?phone=01811111111');

        $response->assertOk()->assertJson(['found' => true, 'cancelled_or_returned' => 1, 'delivered' => 0]);
    }

    public function test_csv_preview_matches_products_and_flags_errors(): void
    {
        [$shop, $owner, $channel] = $this->setupShop();
        Product::create(['shop_id' => $shop->id, 'name' => 'Rice', 'cost' => 50, 'price' => 100, 'stock' => 10]);

        $csv = "phone,name,product,qty,delivery_charge,cod_amount,external_reference\n"
            ."01811111111,Karim,Rice,2,50,250,REF-1\n"
            ."01822222222,Jamal,Unknown Product,1,0,100,REF-2\n";
        $file = UploadedFile::fake()->createWithContent('orders.csv', $csv);

        $response = $this->actingAs($owner, 'web')->post(route('app.onlineOrders.csvPreview'), [
            'channel_id' => $channel->id, 'file' => $file,
        ]);

        $response->assertOk();
        $rows = $response->json('rows');
        $this->assertCount(2, $rows);
        $this->assertTrue($rows[0]['ok']);
        $this->assertSame('Rice', $rows[0]['product_name']);
        $this->assertFalse($rows[1]['ok']);
    }

    public function test_csv_import_creates_orders_for_valid_rows_only(): void
    {
        [$shop, $owner, $channel] = $this->setupShop();
        $product = Product::create(['shop_id' => $shop->id, 'name' => 'Rice', 'cost' => 50, 'price' => 100, 'stock' => 10]);

        $response = $this->actingAs($owner, 'web')->post(route('app.onlineOrders.csvImport'), [
            'channel_id' => $channel->id,
            'rows' => [
                ['phone' => '01811111111', 'name' => 'Karim', 'product_id' => $product->id, 'qty' => 2, 'delivery_charge' => 50, 'cod_amount' => 250, 'external_reference' => 'REF-1'],
            ],
        ]);

        $response->assertRedirect();
        $this->assertSame(1, OnlineOrder::count());
        $this->assertEquals(8, (float) $product->fresh()->stock);
    }

    public function test_csv_preview_flags_a_duplicate_external_reference(): void
    {
        [$shop, $owner, $channel] = $this->setupShop();
        $product = Product::create(['shop_id' => $shop->id, 'name' => 'Rice', 'cost' => 50, 'price' => 100, 'stock' => 10]);

        $this->actingAs($owner, 'web')->post(route('app.onlineOrders.csvImport'), [
            'channel_id' => $channel->id,
            'rows' => [['phone' => '01811111111', 'product_id' => $product->id, 'qty' => 1, 'external_reference' => 'REF-DUP']],
        ])->assertRedirect();

        $csv = "phone,name,product,qty,delivery_charge,cod_amount,external_reference\n01822222222,Jamal,Rice,1,0,100,REF-DUP\n";
        $file = UploadedFile::fake()->createWithContent('orders.csv', $csv);

        $response = $this->actingAs($owner, 'web')->post(route('app.onlineOrders.csvPreview'), [
            'channel_id' => $channel->id, 'file' => $file,
        ]);

        $rows = $response->json('rows');
        $this->assertFalse($rows[0]['ok']);
    }
}
