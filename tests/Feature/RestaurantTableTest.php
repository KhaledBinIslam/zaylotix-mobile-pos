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
 * Restaurant order-then-bill flow: unlike PosController's instant checkout,
 * items get added to an open table tab over time (stock leaves at add-item
 * time), and only billing converts the tab into a real, standard Sale.
 */
class RestaurantTableTest extends TestCase
{
    use RefreshDatabase, CreatesShops;

    public function test_opening_a_table_creates_an_order_and_marks_it_occupied(): void
    {
        [$shop, $owner] = $this->createShopWithOwner();
        $this->grantFeature($shop, 'restaurant_tables');
        $table = RestaurantTable::create(['shop_id' => $shop->id, 'name' => 'T1', 'status' => 'free']);

        $this->actingAs($owner, 'web')->post("/app/restaurant/tables/{$table->id}/open")->assertRedirect();

        $this->assertSame('occupied', $table->fresh()->status);
        $this->assertSame(1, TableOrder::where('restaurant_table_id', $table->id)->where('status', 'open')->count());
    }

    public function test_adding_an_item_decrements_stock_immediately(): void
    {
        [$shop, $owner] = $this->createShopWithOwner();
        $this->grantFeature($shop, 'restaurant_tables');
        $table = RestaurantTable::create(['shop_id' => $shop->id, 'name' => 'T1', 'status' => 'occupied']);
        $order = TableOrder::create(['shop_id' => $shop->id, 'restaurant_table_id' => $table->id, 'status' => 'open', 'opened_at' => now()]);
        $product = Product::create(['shop_id' => $shop->id, 'name' => 'Biryani', 'cost' => 100, 'price' => 200, 'stock' => 10]);

        $this->actingAs($owner, 'web')->post("/app/restaurant/orders/{$order->id}/items", [
            'product_id' => $product->id, 'qty' => 2,
        ])->assertRedirect();

        $this->assertEquals(8, $product->fresh()->stock);
        $this->assertSame(1, TableOrderItem::where('table_order_id', $order->id)->count());
    }

    public function test_adding_the_same_product_twice_merges_into_one_unprinted_line(): void
    {
        [$shop, $owner] = $this->createShopWithOwner();
        $this->grantFeature($shop, 'restaurant_tables');
        $table = RestaurantTable::create(['shop_id' => $shop->id, 'name' => 'T1', 'status' => 'occupied']);
        $order = TableOrder::create(['shop_id' => $shop->id, 'restaurant_table_id' => $table->id, 'status' => 'open', 'opened_at' => now()]);
        $product = Product::create(['shop_id' => $shop->id, 'name' => 'Biryani', 'cost' => 100, 'price' => 200, 'stock' => 10]);

        $this->actingAs($owner, 'web')->post("/app/restaurant/orders/{$order->id}/items", ['product_id' => $product->id, 'qty' => 2]);
        $this->actingAs($owner, 'web')->post("/app/restaurant/orders/{$order->id}/items", ['product_id' => $product->id, 'qty' => 1]);

        $this->assertSame(1, TableOrderItem::where('table_order_id', $order->id)->count());
        $this->assertSame(3, TableOrderItem::first()->qty);
        $this->assertEquals(7, $product->fresh()->stock);
    }

    public function test_adding_the_same_product_after_kot_print_starts_a_new_line(): void
    {
        [$shop, $owner] = $this->createShopWithOwner();
        $this->grantFeature($shop, 'restaurant_tables');
        $table = RestaurantTable::create(['shop_id' => $shop->id, 'name' => 'T1', 'status' => 'occupied']);
        $order = TableOrder::create(['shop_id' => $shop->id, 'restaurant_table_id' => $table->id, 'status' => 'open', 'opened_at' => now()]);
        $product = Product::create(['shop_id' => $shop->id, 'name' => 'Biryani', 'cost' => 100, 'price' => 200, 'stock' => 10]);

        $this->actingAs($owner, 'web')->post("/app/restaurant/orders/{$order->id}/items", ['product_id' => $product->id, 'qty' => 2]);
        $this->actingAs($owner, 'web')->post("/app/restaurant/orders/{$order->id}/kot");
        $this->actingAs($owner, 'web')->post("/app/restaurant/orders/{$order->id}/items", ['product_id' => $product->id, 'qty' => 1]);

        $this->assertSame(2, TableOrderItem::where('table_order_id', $order->id)->count());
    }

    public function test_decrementing_a_line_by_one_restores_one_unit_of_stock(): void
    {
        [$shop, $owner] = $this->createShopWithOwner();
        $this->grantFeature($shop, 'restaurant_tables');
        $table = RestaurantTable::create(['shop_id' => $shop->id, 'name' => 'T1', 'status' => 'occupied']);
        $order = TableOrder::create(['shop_id' => $shop->id, 'restaurant_table_id' => $table->id, 'status' => 'open', 'opened_at' => now()]);
        $product = Product::create(['shop_id' => $shop->id, 'name' => 'Biryani', 'cost' => 100, 'price' => 200, 'stock' => 10]);
        $item = TableOrderItem::create(['shop_id' => $shop->id, 'table_order_id' => $order->id, 'product_id' => $product->id, 'product_name' => 'Biryani', 'qty' => 3, 'price' => 200, 'cost' => 100]);
        $product->decrement('stock', 3);

        $this->actingAs($owner, 'web')->patch("/app/restaurant/order-items/{$item->id}/decrement")->assertRedirect();

        $this->assertEquals(8, $product->fresh()->stock);
        $this->assertSame(2, $item->fresh()->qty);

        // decrementing the last unit removes the line entirely
        $this->actingAs($owner, 'web')->patch("/app/restaurant/order-items/{$item->id}/decrement");
        $this->actingAs($owner, 'web')->patch("/app/restaurant/order-items/{$item->id}/decrement");

        $this->assertEquals(10, $product->fresh()->stock);
        $this->assertSame(0, TableOrderItem::count());
    }

    public function test_removing_an_item_restores_stock(): void
    {
        [$shop, $owner] = $this->createShopWithOwner();
        $this->grantFeature($shop, 'restaurant_tables');
        $table = RestaurantTable::create(['shop_id' => $shop->id, 'name' => 'T1', 'status' => 'occupied']);
        $order = TableOrder::create(['shop_id' => $shop->id, 'restaurant_table_id' => $table->id, 'status' => 'open', 'opened_at' => now()]);
        $product = Product::create(['shop_id' => $shop->id, 'name' => 'Biryani', 'cost' => 100, 'price' => 200, 'stock' => 10]);
        $item = TableOrderItem::create(['shop_id' => $shop->id, 'table_order_id' => $order->id, 'product_id' => $product->id, 'product_name' => 'Biryani', 'qty' => 3, 'price' => 200, 'cost' => 100]);
        $product->decrement('stock', 3);

        $this->actingAs($owner, 'web')->delete("/app/restaurant/order-items/{$item->id}")->assertRedirect();

        $this->assertEquals(10, $product->fresh()->stock);
        $this->assertSame(0, TableOrderItem::count());
    }

    public function test_cannot_oversell_a_table_order_item(): void
    {
        [$shop, $owner] = $this->createShopWithOwner();
        $this->grantFeature($shop, 'restaurant_tables');
        $table = RestaurantTable::create(['shop_id' => $shop->id, 'name' => 'T1', 'status' => 'occupied']);
        $order = TableOrder::create(['shop_id' => $shop->id, 'restaurant_table_id' => $table->id, 'status' => 'open', 'opened_at' => now()]);
        $product = Product::create(['shop_id' => $shop->id, 'name' => 'Biryani', 'cost' => 100, 'price' => 200, 'stock' => 2]);

        $this->actingAs($owner, 'web')->post("/app/restaurant/orders/{$order->id}/items", [
            'product_id' => $product->id, 'qty' => 5,
        ])->assertStatus(422);

        $this->assertEquals(2, $product->fresh()->stock);
        $this->assertSame(0, TableOrderItem::count());
    }

    public function test_billing_creates_a_real_sale_and_does_not_double_decrement_stock(): void
    {
        [$shop, $owner] = $this->createShopWithOwner(['cash_balance' => 1000]);
        $this->grantFeature($shop, 'restaurant_tables');
        $table = RestaurantTable::create(['shop_id' => $shop->id, 'name' => 'T1', 'status' => 'occupied']);
        $order = TableOrder::create(['shop_id' => $shop->id, 'restaurant_table_id' => $table->id, 'status' => 'open', 'opened_at' => now()]);
        $product = Product::create(['shop_id' => $shop->id, 'name' => 'Biryani', 'cost' => 100, 'price' => 200, 'stock' => 10]);
        TableOrderItem::create(['shop_id' => $shop->id, 'table_order_id' => $order->id, 'product_id' => $product->id, 'product_name' => 'Biryani', 'qty' => 3, 'price' => 200, 'cost' => 100]);
        $product->decrement('stock', 3); // simulate what addItem already did

        $response = $this->actingAs($owner, 'web')->post("/app/restaurant/orders/{$order->id}/bill", [
            'payments' => [['method' => 'cash', 'amount' => 600]],
        ]);

        $response->assertRedirect();
        $this->assertEquals(7, $product->fresh()->stock); // still just 10 - 3, not decremented again
        $this->assertSame(1, Sale::count());
        $sale = Sale::first();
        $this->assertEquals(600.0, (float) $sale->total);
        $this->assertEquals(1000 + 600, (float) $shop->fresh()->cash_balance);

        $this->assertSame('billed', $order->fresh()->status);
        $this->assertSame($sale->id, $order->fresh()->sale_id);
        $this->assertSame('free', $table->fresh()->status);
    }

    public function test_billing_with_a_due_remainder_requires_a_customer(): void
    {
        [$shop, $owner] = $this->createShopWithOwner();
        $this->grantFeature($shop, 'restaurant_tables');
        $table = RestaurantTable::create(['shop_id' => $shop->id, 'name' => 'T1', 'status' => 'occupied']);
        $order = TableOrder::create(['shop_id' => $shop->id, 'restaurant_table_id' => $table->id, 'status' => 'open', 'opened_at' => now()]);
        $product = Product::create(['shop_id' => $shop->id, 'name' => 'Biryani', 'cost' => 100, 'price' => 200, 'stock' => 10]);
        TableOrderItem::create(['shop_id' => $shop->id, 'table_order_id' => $order->id, 'product_id' => $product->id, 'product_name' => 'Biryani', 'qty' => 3, 'price' => 200, 'cost' => 100]);

        $response = $this->actingAs($owner, 'web')->post("/app/restaurant/orders/{$order->id}/bill", [
            'payments' => [['method' => 'cash', 'amount' => 300]], // only half of 600
        ]);

        $response->assertStatus(422);
        $this->assertSame('open', $order->fresh()->status); // nothing committed
        $this->assertSame(0, Sale::count());
    }

    public function test_voiding_a_billed_table_sale_restores_stock_via_the_normal_sale_reversal(): void
    {
        [$shop, $owner] = $this->createShopWithOwner(['cash_balance' => 1000]);
        $this->grantFeature($shop, 'restaurant_tables');
        $table = RestaurantTable::create(['shop_id' => $shop->id, 'name' => 'T1', 'status' => 'occupied']);
        $order = TableOrder::create(['shop_id' => $shop->id, 'restaurant_table_id' => $table->id, 'status' => 'open', 'opened_at' => now()]);
        $product = Product::create(['shop_id' => $shop->id, 'name' => 'Biryani', 'cost' => 100, 'price' => 200, 'stock' => 10]);
        TableOrderItem::create(['shop_id' => $shop->id, 'table_order_id' => $order->id, 'product_id' => $product->id, 'product_name' => 'Biryani', 'qty' => 3, 'price' => 200, 'cost' => 100]);
        $product->decrement('stock', 3);

        $bill = $this->actingAs($owner, 'web')->post("/app/restaurant/orders/{$order->id}/bill", [
            'payments' => [['method' => 'cash', 'amount' => 600]],
        ]);
        $bill->assertRedirect();
        $saleId = $order->fresh()->sale_id;
        $this->assertEquals(7, $product->fresh()->stock);

        $this->actingAs($owner, 'web')->delete("/app/sales/{$saleId}", ['reason' => 'Kitchen made a mistake'])
            ->assertRedirect();

        $this->assertEquals(10, $product->fresh()->stock); // fully restored, no restaurant-specific code needed
        $this->assertEquals(1000.0, (float) $shop->fresh()->cash_balance);
    }

    public function test_cancelling_an_open_order_restores_stock_and_frees_the_table(): void
    {
        [$shop, $owner] = $this->createShopWithOwner();
        $this->grantFeature($shop, 'restaurant_tables');
        $table = RestaurantTable::create(['shop_id' => $shop->id, 'name' => 'T1', 'status' => 'occupied']);
        $order = TableOrder::create(['shop_id' => $shop->id, 'restaurant_table_id' => $table->id, 'status' => 'open', 'opened_at' => now()]);
        $product = Product::create(['shop_id' => $shop->id, 'name' => 'Biryani', 'cost' => 100, 'price' => 200, 'stock' => 10]);
        TableOrderItem::create(['shop_id' => $shop->id, 'table_order_id' => $order->id, 'product_id' => $product->id, 'product_name' => 'Biryani', 'qty' => 4, 'price' => 200, 'cost' => 100]);
        $product->decrement('stock', 4);

        $this->actingAs($owner, 'web')->post("/app/restaurant/orders/{$order->id}/cancel")->assertRedirect();

        $this->assertEquals(10, $product->fresh()->stock);
        $this->assertSame('cancelled', $order->fresh()->status);
        $this->assertSame('free', $table->fresh()->status);
    }

    public function test_cannot_act_on_an_already_billed_order(): void
    {
        [$shop, $owner] = $this->createShopWithOwner();
        $this->grantFeature($shop, 'restaurant_tables');
        $table = RestaurantTable::create(['shop_id' => $shop->id, 'name' => 'T1', 'status' => 'free']);
        $order = TableOrder::create(['shop_id' => $shop->id, 'restaurant_table_id' => $table->id, 'status' => 'billed', 'opened_at' => now()]);
        $product = Product::create(['shop_id' => $shop->id, 'name' => 'Biryani', 'cost' => 100, 'price' => 200, 'stock' => 10]);

        $this->actingAs($owner, 'web')->post("/app/restaurant/orders/{$order->id}/items", [
            'product_id' => $product->id, 'qty' => 1,
        ])->assertStatus(422);

        $this->assertEquals(10, $product->fresh()->stock);
    }

    public function test_tables_are_tenant_scoped(): void
    {
        [$shopA, $ownerA] = $this->createShopWithOwner();
        $this->grantFeature($shopA, 'restaurant_tables');
        [$shopB] = $this->createShopWithOwner();
        $tableB = RestaurantTable::create(['shop_id' => $shopB->id, 'name' => 'T-B', 'status' => 'free']);

        $this->actingAs($ownerA, 'web')->post("/app/restaurant/tables/{$tableB->id}/open")->assertNotFound();
    }
}
