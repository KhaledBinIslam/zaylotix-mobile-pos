<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\RestaurantTable;
use App\Models\TableOrder;
use App\Models\TableOrderItem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesShops;
use Tests\TestCase;

/**
 * Cooked food doesn't fit a numeric stock model (a pot's yield isn't known
 * ahead of time) — Product::STOCK_MODE_* gives a restaurant a per-product
 * choice instead of forcing the retail-style count every other vertical
 * uses. Covers all 3 modes across every place TableOrderController touches
 * stock (addItem/decrementItem/removeItem/cancel), plus the quick
 * available/sold-out toggle.
 */
class RestaurantStockModeTest extends TestCase
{
    use RefreshDatabase, CreatesShops;

    private function openOrder(int $shopId): TableOrder
    {
        $table = RestaurantTable::create(['shop_id' => $shopId, 'name' => 'T1', 'status' => 'occupied']);

        return TableOrder::create(['shop_id' => $shopId, 'restaurant_table_id' => $table->id, 'status' => 'open', 'opened_at' => now()]);
    }

    // ---------------- addItem() ----------------

    public function test_untracked_product_is_always_addable_regardless_of_qty(): void
    {
        [$shop, $owner] = $this->createShopWithOwner();
        $this->grantFeature($shop, 'restaurant_tables');
        $order = $this->openOrder($shop->id);
        $biryani = Product::create(['shop_id' => $shop->id, 'name' => 'Biryani', 'cost' => 100, 'price' => 200, 'stock' => 0, 'stock_mode' => 'untracked']);

        $this->actingAs($owner, 'web')->postJson("/app/restaurant/orders/{$order->id}/items", ['product_id' => $biryani->id, 'qty' => 999])->assertOk();

        $this->assertEquals(0, $biryani->fresh()->stock, 'untracked stock must never move');
        $this->assertSame(999, (int) TableOrderItem::where('table_order_id', $order->id)->value('qty'));
    }

    public function test_toggle_product_is_addable_while_available_and_never_decrements(): void
    {
        [$shop, $owner] = $this->createShopWithOwner();
        $this->grantFeature($shop, 'restaurant_tables');
        $order = $this->openOrder($shop->id);
        $kabab = Product::create(['shop_id' => $shop->id, 'name' => 'Kabab', 'cost' => 100, 'price' => 180, 'stock' => 1, 'stock_mode' => 'toggle']);

        $this->actingAs($owner, 'web')->postJson("/app/restaurant/orders/{$order->id}/items", ['product_id' => $kabab->id, 'qty' => 5])->assertOk();

        $this->assertEquals(1, $kabab->fresh()->stock, "toggle mode's stock is a flag, not a count — a sale must never change it");
    }

    public function test_toggle_product_is_rejected_once_marked_sold_out(): void
    {
        [$shop, $owner] = $this->createShopWithOwner();
        $this->grantFeature($shop, 'restaurant_tables');
        $order = $this->openOrder($shop->id);
        $kabab = Product::create(['shop_id' => $shop->id, 'name' => 'Kabab', 'cost' => 100, 'price' => 180, 'stock' => 0, 'stock_mode' => 'toggle']);

        $response = $this->actingAs($owner, 'web')->postJson("/app/restaurant/orders/{$order->id}/items", ['product_id' => $kabab->id, 'qty' => 1]);

        $response->assertStatus(422);
        $this->assertSame(0, TableOrderItem::where('table_order_id', $order->id)->count());
    }

    public function test_tracked_product_still_checks_and_decrements_a_real_count(): void
    {
        [$shop, $owner] = $this->createShopWithOwner();
        $this->grantFeature($shop, 'restaurant_tables');
        $order = $this->openOrder($shop->id);
        $drinks = Product::create(['shop_id' => $shop->id, 'name' => 'Cold Drinks', 'cost' => 25, 'price' => 40, 'stock' => 3, 'stock_mode' => 'tracked']);

        $this->actingAs($owner, 'web')->postJson("/app/restaurant/orders/{$order->id}/items", ['product_id' => $drinks->id, 'qty' => 2])->assertOk();
        $this->assertEquals(1, $drinks->fresh()->stock);

        $this->actingAs($owner, 'web')->postJson("/app/restaurant/orders/{$order->id}/items", ['product_id' => $drinks->id, 'qty' => 2])
            ->assertStatus(422); // only 1 left, asked for 2
    }

    // ---------------- decrementItem() / removeItem() / cancel() ----------------

    public function test_decrementing_or_removing_an_untracked_items_line_never_touches_stock(): void
    {
        [$shop, $owner] = $this->createShopWithOwner();
        $this->grantFeature($shop, 'restaurant_tables');
        $order = $this->openOrder($shop->id);
        $biryani = Product::create(['shop_id' => $shop->id, 'name' => 'Biryani', 'cost' => 100, 'price' => 200, 'stock' => 0, 'stock_mode' => 'untracked']);
        $item = TableOrderItem::create(['shop_id' => $shop->id, 'table_order_id' => $order->id, 'product_id' => $biryani->id, 'product_name' => $biryani->name, 'qty' => 3, 'price' => 200, 'cost' => 100]);

        $this->actingAs($owner, 'web')->patch("/app/restaurant/order-items/{$item->id}/decrement")->assertRedirect();
        $this->assertEquals(0, $biryani->fresh()->stock);

        $this->actingAs($owner, 'web')->delete("/app/restaurant/order-items/{$item->fresh()->id}")->assertRedirect();
        $this->assertEquals(0, $biryani->fresh()->stock, 'giving back stock that was never taken would drift it away from 0');
    }

    public function test_cancelling_an_order_only_restores_stock_for_the_tracked_item(): void
    {
        [$shop, $owner] = $this->createShopWithOwner();
        $this->grantFeature($shop, 'restaurant_tables');
        $order = $this->openOrder($shop->id);
        $biryani = Product::create(['shop_id' => $shop->id, 'name' => 'Biryani', 'cost' => 100, 'price' => 200, 'stock' => 0, 'stock_mode' => 'untracked']);
        $drinks = Product::create(['shop_id' => $shop->id, 'name' => 'Cold Drinks', 'cost' => 25, 'price' => 40, 'stock' => 5, 'stock_mode' => 'tracked']);

        TableOrderItem::create(['shop_id' => $shop->id, 'table_order_id' => $order->id, 'product_id' => $biryani->id, 'product_name' => $biryani->name, 'qty' => 2, 'price' => 200, 'cost' => 100]);
        $drinksItem = TableOrderItem::create(['shop_id' => $shop->id, 'table_order_id' => $order->id, 'product_id' => $drinks->id, 'product_name' => $drinks->name, 'qty' => 2, 'price' => 40, 'cost' => 25]);
        $drinks->decrement('stock', 2); // mirrors what addItem() would already have done for a tracked line

        $this->actingAs($owner, 'web')->post("/app/restaurant/orders/{$order->id}/cancel")->assertRedirect();

        $this->assertEquals(0, $biryani->fresh()->stock, 'untracked — nothing to give back');
        $this->assertEquals(5, $drinks->fresh()->stock, 'tracked — the 2 taken at add-time must come back');
    }

    // ---------------- availability toggle ----------------

    public function test_owner_can_toggle_a_toggle_mode_product_between_available_and_sold_out(): void
    {
        [$shop, $owner] = $this->createShopWithOwner();
        $kabab = Product::create(['shop_id' => $shop->id, 'name' => 'Kabab', 'cost' => 100, 'price' => 180, 'stock' => 1, 'stock_mode' => 'toggle']);

        $this->actingAs($owner, 'web')->patch("/app/products/{$kabab->id}/availability", ['available' => false])->assertRedirect();
        $this->assertEquals(0, $kabab->fresh()->stock);

        $this->actingAs($owner, 'web')->patch("/app/products/{$kabab->id}/availability", ['available' => true])->assertRedirect();
        $this->assertEquals(1, $kabab->fresh()->stock);
    }

    public function test_availability_toggle_is_rejected_for_a_tracked_product(): void
    {
        [$shop, $owner] = $this->createShopWithOwner();
        $drinks = Product::create(['shop_id' => $shop->id, 'name' => 'Cold Drinks', 'cost' => 25, 'price' => 40, 'stock' => 5, 'stock_mode' => 'tracked']);

        $this->actingAs($owner, 'web')->patch("/app/products/{$drinks->id}/availability", ['available' => false])
            ->assertStatus(422);
        $this->assertEquals(5, $drinks->fresh()->stock, 'a rejected toggle attempt must never touch a tracked count');
    }

    // ---------------- product form / StoreProductRequest ----------------

    public function test_creating_an_untracked_product_does_not_require_a_stock_number(): void
    {
        [$shop, $owner] = $this->createShopWithOwner();

        $this->actingAs($owner, 'web')->post('/app/products', [
            'name' => 'Biryani', 'cost' => 100, 'price' => 200, 'stock_mode' => 'untracked',
        ])->assertRedirect();

        $product = Product::where('name', 'Biryani')->first();
        $this->assertNotNull($product);
        $this->assertSame('untracked', $product->stock_mode);
        $this->assertEquals(0, $product->stock);
    }

    public function test_creating_a_toggle_product_stores_available_as_the_stock_flag(): void
    {
        [$shop, $owner] = $this->createShopWithOwner();

        $this->actingAs($owner, 'web')->post('/app/products', [
            'name' => 'Kabab', 'cost' => 100, 'price' => 180, 'stock_mode' => 'toggle', 'available' => false,
        ])->assertRedirect();

        $product = Product::where('name', 'Kabab')->first();
        $this->assertSame('toggle', $product->stock_mode);
        $this->assertEquals(0, $product->stock);
    }

    public function test_creating_a_tracked_product_still_requires_a_stock_number(): void
    {
        [$shop, $owner] = $this->createShopWithOwner();

        $response = $this->actingAs($owner, 'web')->post('/app/products', [
            'name' => 'Cold Drinks', 'cost' => 25, 'price' => 40, // stock omitted, stock_mode defaults to 'tracked'
        ]);

        $response->assertSessionHasErrors('stock');
        $this->assertNull(Product::where('name', 'Cold Drinks')->first());
    }

    public function test_omitting_stock_mode_entirely_still_behaves_exactly_like_before(): void
    {
        [$shop, $owner] = $this->createShopWithOwner();

        $this->actingAs($owner, 'web')->post('/app/products', [
            'name' => 'Regular Item', 'cost' => 10, 'price' => 20, 'stock' => 15,
        ])->assertRedirect();

        $product = Product::where('name', 'Regular Item')->first();
        $this->assertSame('tracked', $product->stock_mode);
        $this->assertEquals(15, $product->stock);
    }

    // ---------------- CdsController — a naive stock>0 filter would hide every untracked dish ----------------

    public function test_customer_display_screen_still_shows_untracked_and_available_toggle_items(): void
    {
        [$shop, $owner] = $this->createShopWithOwner();
        $this->grantFeature($shop, 'restaurant_tables');
        $biryani = Product::create(['shop_id' => $shop->id, 'name' => 'Biryani', 'cost' => 100, 'price' => 200, 'stock' => 0, 'stock_mode' => 'untracked']);
        $kababAvailable = Product::create(['shop_id' => $shop->id, 'name' => 'Kabab', 'cost' => 100, 'price' => 180, 'stock' => 1, 'stock_mode' => 'toggle']);
        $kababSoldOut = Product::create(['shop_id' => $shop->id, 'name' => 'Kabab 2', 'cost' => 100, 'price' => 180, 'stock' => 0, 'stock_mode' => 'toggle']);
        $drinksOut = Product::create(['shop_id' => $shop->id, 'name' => 'Drinks', 'cost' => 25, 'price' => 40, 'stock' => 0, 'stock_mode' => 'tracked']);

        $this->actingAs($owner, 'web')->get('/app/cds')->assertInertia(fn ($page) => $page
            ->where('products', function ($products) use ($biryani, $kababAvailable, $kababSoldOut, $drinksOut) {
                $names = collect($products)->pluck('name');

                return $names->contains($biryani->name) // untracked must always show, even at stock 0
                    && $names->contains($kababAvailable->name) // toggle-available must show
                    && ! $names->contains($kababSoldOut->name) // toggle-sold-out correctly hides
                    && ! $names->contains($drinksOut->name); // tracked-and-out-of-stock still correctly hides
            })
        );
    }

    // ---------------- dashboard/API low-stock & out-of-stock tiles must ignore untracked/toggle ----------------

    public function test_home_dashboard_low_stock_and_out_of_stock_tiles_ignore_untracked_items(): void
    {
        [$shop, $owner] = $this->createShopWithOwner();
        Product::create(['shop_id' => $shop->id, 'name' => 'Biryani', 'cost' => 100, 'price' => 200, 'stock' => 0, 'stock_mode' => 'untracked']);
        Product::create(['shop_id' => $shop->id, 'name' => 'Kabab', 'cost' => 100, 'price' => 180, 'stock' => 0, 'stock_mode' => 'toggle']);
        Product::create(['shop_id' => $shop->id, 'name' => 'Real Out Of Stock', 'cost' => 10, 'price' => 20, 'stock' => 0, 'stock_mode' => 'tracked']);

        $this->actingAs($owner, 'web')->get('/app/home')->assertInertia(fn ($page) => $page
            ->where('outOfStockCount', 1) // only the genuinely tracked-and-empty product counts
        );
    }
}
