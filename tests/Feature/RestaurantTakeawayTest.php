<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\Sale;
use App\Models\TableOrder;
use App\Models\TableOrderItem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesShops;
use Tests\TestCase;

/** Takeaway/parcel orders have no physical table at all (restaurant_table_id is null) — see RestaurantTableController::openTakeaway. */
class RestaurantTakeawayTest extends TestCase
{
    use RefreshDatabase, CreatesShops;

    public function test_starting_a_takeaway_order_creates_one_with_no_table(): void
    {
        [$shop, $owner] = $this->createShopWithOwner();
        $this->grantFeature($shop, 'restaurant_tables');

        $response = $this->actingAs($owner, 'web')->post('/app/restaurant/takeaway');

        $order = TableOrder::first();
        $response->assertRedirect(route('app.restaurant.orders.show', $order->id));
        $this->assertNull($order->restaurant_table_id);
        $this->assertSame('open', $order->status);
        $this->assertSame('takeaway', $order->order_source);
    }

    public function test_takeaway_order_show_page_works_with_no_table(): void
    {
        [$shop, $owner] = $this->createShopWithOwner();
        $this->grantFeature($shop, 'restaurant_tables');
        $order = TableOrder::create(['shop_id' => $shop->id, 'restaurant_table_id' => null, 'status' => 'open', 'order_source' => 'takeaway', 'opened_at' => now()]);

        $response = $this->actingAs($owner, 'web')->get("/app/restaurant/orders/{$order->id}");

        $response->assertOk()->assertInertia(fn ($page) => $page
            ->where('order.table_id', null)
            ->where('order.table_name', null)
        );
    }

    public function test_takeaway_order_can_be_billed_without_a_table(): void
    {
        [$shop, $owner] = $this->createShopWithOwner(['cash_balance' => 1000]);
        $this->grantFeature($shop, 'restaurant_tables');
        $order = TableOrder::create(['shop_id' => $shop->id, 'restaurant_table_id' => null, 'status' => 'open', 'order_source' => 'takeaway', 'opened_at' => now()]);
        $product = Product::create(['shop_id' => $shop->id, 'name' => 'Burger', 'cost' => 50, 'price' => 150, 'stock' => 10]);
        TableOrderItem::create(['shop_id' => $shop->id, 'table_order_id' => $order->id, 'product_id' => $product->id, 'product_name' => 'Burger', 'qty' => 2, 'price' => 150, 'cost' => 50]);
        $product->decrement('stock', 2);

        $response = $this->actingAs($owner, 'web')->post("/app/restaurant/orders/{$order->id}/bill", [
            'payments' => [['method' => 'cash', 'amount' => 300]],
        ]);

        $response->assertRedirect();
        $this->assertSame('billed', $order->fresh()->status);
        $this->assertSame(1, Sale::count());
        $this->assertEquals(300.0, (float) Sale::first()->total);
    }

    public function test_takeaway_order_can_be_cancelled_and_restores_stock(): void
    {
        [$shop, $owner] = $this->createShopWithOwner();
        $this->grantFeature($shop, 'restaurant_tables');
        $order = TableOrder::create(['shop_id' => $shop->id, 'restaurant_table_id' => null, 'status' => 'open', 'order_source' => 'takeaway', 'opened_at' => now()]);
        $product = Product::create(['shop_id' => $shop->id, 'name' => 'Burger', 'cost' => 50, 'price' => 150, 'stock' => 10]);
        TableOrderItem::create(['shop_id' => $shop->id, 'table_order_id' => $order->id, 'product_id' => $product->id, 'product_name' => 'Burger', 'qty' => 2, 'price' => 150, 'cost' => 50]);
        $product->decrement('stock', 2);

        $response = $this->actingAs($owner, 'web')->post("/app/restaurant/orders/{$order->id}/cancel");

        $response->assertRedirect();
        $this->assertSame('cancelled', $order->fresh()->status);
        $this->assertEquals(10, $product->fresh()->stock);
    }

    public function test_tables_index_lists_active_takeaway_orders_separately(): void
    {
        [$shop, $owner] = $this->createShopWithOwner();
        $this->grantFeature($shop, 'restaurant_tables');
        TableOrder::create(['shop_id' => $shop->id, 'restaurant_table_id' => null, 'status' => 'open', 'order_source' => 'takeaway', 'opened_at' => now()]);

        $response = $this->actingAs($owner, 'web')->get('/app/restaurant/tables');

        $response->assertOk()->assertInertia(fn ($page) => $page
            ->has('takeawayOrders', 1)
            ->where('takeawayOrders.0.order_source', 'takeaway')
        );
    }

    public function test_adding_an_item_to_a_takeaway_order_works_normally(): void
    {
        [$shop, $owner] = $this->createShopWithOwner();
        $this->grantFeature($shop, 'restaurant_tables');
        $order = TableOrder::create(['shop_id' => $shop->id, 'restaurant_table_id' => null, 'status' => 'open', 'order_source' => 'takeaway', 'opened_at' => now()]);
        $product = Product::create(['shop_id' => $shop->id, 'name' => 'Burger', 'cost' => 50, 'price' => 150, 'stock' => 10]);

        $response = $this->actingAs($owner, 'web')->post("/app/restaurant/orders/{$order->id}/items", [
            'product_id' => $product->id, 'qty' => 1,
        ]);

        $response->assertRedirect();
        $this->assertEquals(9, $product->fresh()->stock);
    }
}
