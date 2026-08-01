<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\RestaurantTable;
use App\Models\Sale;
use App\Models\TableOrder;
use App\Models\TableOrderItem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesShops;
use Tests\TestCase;

/**
 * The restaurant order extras added on top of RestaurantTableTest's core
 * order-then-bill flow: order source (dine-in/takeaway/3rd-party delivery),
 * a free-text kitchen instruction note, per-item served/not-served
 * tracking (separate from kot_printed_at), and the kitchen WhatsApp number
 * setting. None of these touch stock or money — pure metadata — so they're
 * tested separately from the stock/cash-moving core flow.
 */
class RestaurantOrderExtrasTest extends TestCase
{
    use RefreshDatabase, CreatesShops;

    private function openOrder($shop): TableOrder
    {
        $table = RestaurantTable::create(['shop_id' => $shop->id, 'name' => 'T1', 'status' => 'occupied']);

        return TableOrder::create(['shop_id' => $shop->id, 'restaurant_table_id' => $table->id, 'status' => 'open', 'opened_at' => now()]);
    }

    public function test_owner_can_set_the_order_source_to_delivery_with_a_platform(): void
    {
        [$shop, $owner] = $this->createShopWithOwner();
        $this->grantFeature($shop, 'restaurant_tables');
        $order = $this->openOrder($shop);

        $response = $this->actingAs($owner, 'web')->patch("/app/restaurant/orders/{$order->id}/meta", [
            'order_source' => 'delivery',
            'delivery_platform' => 'Food Panda',
            'kitchen_note' => 'কম ঝাল',
        ]);

        $response->assertRedirect();
        $order->refresh();
        $this->assertSame('delivery', $order->order_source);
        $this->assertSame('Food Panda', $order->delivery_platform);
        $this->assertSame('কম ঝাল', $order->kitchen_note);
    }

    public function test_delivery_platform_is_cleared_when_source_is_not_delivery(): void
    {
        [$shop, $owner] = $this->createShopWithOwner();
        $this->grantFeature($shop, 'restaurant_tables');
        $order = $this->openOrder($shop);
        $order->update(['order_source' => 'delivery', 'delivery_platform' => 'Pathao Food']);

        $this->actingAs($owner, 'web')->patch("/app/restaurant/orders/{$order->id}/meta", [
            'order_source' => 'dine_in',
        ])->assertRedirect();

        $this->assertNull($order->fresh()->delivery_platform);
    }

    public function test_an_invalid_order_source_is_rejected(): void
    {
        [$shop, $owner] = $this->createShopWithOwner();
        $this->grantFeature($shop, 'restaurant_tables');
        $order = $this->openOrder($shop);

        $this->actingAs($owner, 'web')->patch("/app/restaurant/orders/{$order->id}/meta", [
            'order_source' => 'moon_delivery',
        ])->assertSessionHasErrors('order_source');
    }

    public function test_meta_cannot_be_changed_on_an_already_billed_order(): void
    {
        [$shop, $owner] = $this->createShopWithOwner();
        $this->grantFeature($shop, 'restaurant_tables');
        $order = $this->openOrder($shop);
        $order->update(['status' => 'billed']);

        $this->actingAs($owner, 'web')->patch("/app/restaurant/orders/{$order->id}/meta", [
            'order_source' => 'takeaway',
        ])->assertStatus(422);
    }

    public function test_toggling_served_marks_and_unmarks_an_item(): void
    {
        [$shop, $owner] = $this->createShopWithOwner();
        $this->grantFeature($shop, 'restaurant_tables');
        $order = $this->openOrder($shop);
        $product = Product::create(['shop_id' => $shop->id, 'name' => 'Biryani', 'cost' => 100, 'price' => 200, 'stock' => 10]);
        $item = TableOrderItem::create(['shop_id' => $shop->id, 'table_order_id' => $order->id, 'product_id' => $product->id, 'product_name' => 'Biryani', 'qty' => 2, 'price' => 200, 'cost' => 100]);

        $this->actingAs($owner, 'web')->post("/app/restaurant/order-items/{$item->id}/toggle-served")->assertRedirect();
        $this->assertNotNull($item->fresh()->served_at);

        // toggling again undoes it
        $this->actingAs($owner, 'web')->post("/app/restaurant/order-items/{$item->id}/toggle-served");
        $this->assertNull($item->fresh()->served_at);
    }

    public function test_served_toggle_is_rejected_once_the_order_is_no_longer_open(): void
    {
        [$shop, $owner] = $this->createShopWithOwner();
        $this->grantFeature($shop, 'restaurant_tables');
        $order = $this->openOrder($shop);
        $product = Product::create(['shop_id' => $shop->id, 'name' => 'Biryani', 'cost' => 100, 'price' => 200, 'stock' => 10]);
        $item = TableOrderItem::create(['shop_id' => $shop->id, 'table_order_id' => $order->id, 'product_id' => $product->id, 'product_name' => 'Biryani', 'qty' => 2, 'price' => 200, 'cost' => 100]);
        $order->update(['status' => 'cancelled']);

        $this->actingAs($owner, 'web')->post("/app/restaurant/order-items/{$item->id}/toggle-served")->assertStatus(422);
        $this->assertNull($item->fresh()->served_at);
    }

    public function test_owner_can_set_the_kitchen_whatsapp_number(): void
    {
        [$shop, $owner] = $this->createShopWithOwner();
        $this->grantFeature($shop, 'restaurant_tables');

        $this->actingAs($owner, 'web')->patch('/app/settings/kitchen-whatsapp', [
            'kitchen_whatsapp' => '01911111111',
        ])->assertRedirect();

        $this->assertSame('01911111111', $shop->fresh()->kitchen_whatsapp);
    }

    public function test_owner_can_set_payment_timing_and_kitchen_print_order(): void
    {
        [$shop, $owner] = $this->createShopWithOwner();
        $this->grantFeature($shop, 'restaurant_tables');

        // defaults match every existing shop's prior (unconfigured) behaviour
        // — refresh first: the in-memory model from create() never had these
        // columns assigned, so it doesn't reflect the DB-side default until reloaded
        $shop->refresh();
        $this->assertSame('pay_later', $shop->payment_timing);
        $this->assertSame('kitchen_first', $shop->kitchen_print_order);

        $this->actingAs($owner, 'web')->patch('/app/settings/restaurant-prefs', [
            'payment_timing' => 'pay_first',
            'kitchen_print_order' => 'customer_first',
        ])->assertRedirect();

        $shop->refresh();
        $this->assertSame('pay_first', $shop->payment_timing);
        $this->assertSame('customer_first', $shop->kitchen_print_order);
    }

    public function test_invalid_restaurant_prefs_are_rejected(): void
    {
        [$shop, $owner] = $this->createShopWithOwner();
        $this->grantFeature($shop, 'restaurant_tables');

        $this->actingAs($owner, 'web')->patch('/app/settings/restaurant-prefs', [
            'payment_timing' => 'sometimes',
            'kitchen_print_order' => 'kitchen_first',
        ])->assertSessionHasErrors('payment_timing');
    }

    public function test_a_restaurant_origin_sale_carries_its_table_order_details_for_the_combined_print(): void
    {
        [$shop, $owner] = $this->createShopWithOwner(['cash_balance' => 1000]);
        $this->grantFeature($shop, 'restaurant_tables');
        $order = $this->openOrder($shop);
        $order->update(['order_source' => 'delivery', 'delivery_platform' => 'Pathao Food', 'kitchen_note' => 'ঝাল বেশি']);
        $product = Product::create(['shop_id' => $shop->id, 'name' => 'Biryani', 'cost' => 100, 'price' => 200, 'stock' => 10]);
        TableOrderItem::create(['shop_id' => $shop->id, 'table_order_id' => $order->id, 'product_id' => $product->id, 'product_name' => 'Biryani', 'qty' => 2, 'price' => 200, 'cost' => 100]);
        $product->decrement('stock', 2);

        $this->actingAs($owner, 'web')->post("/app/restaurant/orders/{$order->id}/bill", [
            'payments' => [['method' => 'cash', 'amount' => 400]],
        ])->assertRedirect();

        $sale = Sale::first();
        $response = $this->actingAs($owner, 'web')->get("/app/sales/{$sale->id}");

        $response->assertOk()->assertInertia(fn ($page) => $page
            ->component('App/Sales/Show')
            ->where('sale.table_order.order_source', 'delivery')
            ->where('sale.table_order.delivery_platform', 'Pathao Food')
            ->where('sale.table_order.kitchen_note', 'ঝাল বেশি')
        );
    }

    public function test_a_plain_pos_sale_has_no_table_order(): void
    {
        [$shop, $owner] = $this->createShopWithOwner();
        $product = Product::create(['shop_id' => $shop->id, 'name' => 'Rice', 'cost' => 50, 'price' => 100, 'stock' => 10]);

        $this->actingAs($owner, 'web')->postJson('/app/pos/checkout', [
            'items' => [['product_id' => $product->id, 'qty' => 1]],
            'payments' => [['method' => 'cash', 'amount' => 100]],
        ])->assertOk();

        $sale = Sale::first();
        $response = $this->actingAs($owner, 'web')->get("/app/sales/{$sale->id}");

        $response->assertOk()->assertInertia(fn ($page) => $page
            ->component('App/Sales/Show')
            ->where('sale.table_order', null)
        );
    }

    public function test_order_meta_and_serve_toggle_are_tenant_scoped(): void
    {
        [$shopA, $ownerA] = $this->createShopWithOwner();
        $this->grantFeature($shopA, 'restaurant_tables');
        [$shopB] = $this->createShopWithOwner();
        $orderB = $this->openOrder($shopB);
        $productB = Product::create(['shop_id' => $shopB->id, 'name' => 'Biryani', 'cost' => 100, 'price' => 200, 'stock' => 10]);
        $itemB = TableOrderItem::create(['shop_id' => $shopB->id, 'table_order_id' => $orderB->id, 'product_id' => $productB->id, 'product_name' => 'Biryani', 'qty' => 1, 'price' => 200, 'cost' => 100]);

        $this->actingAs($ownerA, 'web')->patch("/app/restaurant/orders/{$orderB->id}/meta", [
            'order_source' => 'takeaway',
        ])->assertNotFound();

        $this->actingAs($ownerA, 'web')->post("/app/restaurant/order-items/{$itemB->id}/toggle-served")->assertNotFound();
        $this->assertNull($itemB->fresh()->served_at);
    }
}
