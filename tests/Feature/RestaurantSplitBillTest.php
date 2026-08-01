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
 * Split-bill: one table order can now produce several sales via bill()'s
 * optional `item_ids` — each split only touches the lines it's given, the
 * rest stays open on the table until a later bill (full or another split)
 * clears it. The table itself only actually frees once nothing is left
 * unbilled.
 */
class RestaurantSplitBillTest extends TestCase
{
    use RefreshDatabase, CreatesShops;

    private function openOrderWithTwoItems(int $shopId): array
    {
        $table = RestaurantTable::create(['shop_id' => $shopId, 'name' => 'T1', 'status' => 'occupied']);
        $order = TableOrder::create(['shop_id' => $shopId, 'restaurant_table_id' => $table->id, 'status' => 'open', 'opened_at' => now()]);
        $biryani = Product::create(['shop_id' => $shopId, 'name' => 'Biryani', 'cost' => 100, 'price' => 200, 'stock' => 10]);
        $cola = Product::create(['shop_id' => $shopId, 'name' => 'Cola', 'cost' => 10, 'price' => 30, 'stock' => 10]);
        $item1 = TableOrderItem::create(['shop_id' => $shopId, 'table_order_id' => $order->id, 'product_id' => $biryani->id, 'product_name' => 'Biryani', 'qty' => 1, 'price' => 200, 'cost' => 100]);
        $item2 = TableOrderItem::create(['shop_id' => $shopId, 'table_order_id' => $order->id, 'product_id' => $cola->id, 'product_name' => 'Cola', 'qty' => 1, 'price' => 30, 'cost' => 10]);
        $biryani->decrement('stock', 1);
        $cola->decrement('stock', 1);

        return [$table, $order, $item1, $item2, $biryani, $cola];
    }

    public function test_billing_a_subset_of_items_leaves_the_rest_open_and_the_table_occupied(): void
    {
        [$shop, $owner] = $this->createShopWithOwner();
        $this->grantFeature($shop, 'restaurant_tables');
        [$table, $order, $item1, $item2] = $this->openOrderWithTwoItems($shop->id);

        $this->actingAs($owner, 'web')->post("/app/restaurant/orders/{$order->id}/bill", [
            'item_ids' => [$item1->id],
            'payments' => [['method' => 'cash', 'amount' => 200]],
        ])->assertRedirect();

        $this->assertSame('open', $order->fresh()->status);
        $this->assertSame('occupied', $table->fresh()->status);
        $this->assertSame(1, Sale::count());
        $this->assertSame($item1->fresh()->sale_id, Sale::first()->id);
        $this->assertNull($item2->fresh()->sale_id); // still open
        $this->assertSame(200.0, (float) Sale::first()->total);
    }

    public function test_billing_the_remaining_item_finally_closes_the_order_and_frees_the_table(): void
    {
        [$shop, $owner] = $this->createShopWithOwner();
        $this->grantFeature($shop, 'restaurant_tables');
        [$table, $order, $item1, $item2] = $this->openOrderWithTwoItems($shop->id);

        $this->actingAs($owner, 'web')->post("/app/restaurant/orders/{$order->id}/bill", [
            'item_ids' => [$item1->id],
            'payments' => [['method' => 'cash', 'amount' => 200]],
        ])->assertRedirect();

        $this->actingAs($owner, 'web')->post("/app/restaurant/orders/{$order->id}/bill", [
            'item_ids' => [$item2->id],
            'payments' => [['method' => 'cash', 'amount' => 30]],
        ])->assertRedirect();

        $this->assertSame('billed', $order->fresh()->status);
        $this->assertSame('free', $table->fresh()->status);
        $this->assertSame(2, Sale::count());
        $this->assertSame(30.0, (float) Sale::query()->latest('id')->first()->total);
    }

    public function test_omitting_item_ids_still_bills_everything_at_once_like_before(): void
    {
        [$shop, $owner] = $this->createShopWithOwner();
        $this->grantFeature($shop, 'restaurant_tables');
        [$table, $order] = $this->openOrderWithTwoItems($shop->id);

        $this->actingAs($owner, 'web')->post("/app/restaurant/orders/{$order->id}/bill", [
            'payments' => [['method' => 'cash', 'amount' => 230]],
        ])->assertRedirect();

        $this->assertSame('billed', $order->fresh()->status);
        $this->assertSame('free', $table->fresh()->status);
        $this->assertSame(1, Sale::count());
        $this->assertSame(230.0, (float) Sale::first()->total);
    }

    public function test_an_item_id_that_does_not_belong_to_this_order_is_rejected(): void
    {
        [$shop, $owner] = $this->createShopWithOwner();
        $this->grantFeature($shop, 'restaurant_tables');
        [, $order, $item1] = $this->openOrderWithTwoItems($shop->id);
        [, $otherOrder] = $this->openOrderWithTwoItems($shop->id); // a second, unrelated order

        $response = $this->actingAs($owner, 'web')->post("/app/restaurant/orders/{$order->id}/bill", [
            'item_ids' => [$otherOrder->items()->first()->id], // foreign item id
            'payments' => [],
        ]);

        $response->assertStatus(422);
        $this->assertSame('open', $order->fresh()->status);
        $this->assertSame(0, Sale::count());
    }

    public function test_an_already_split_billed_item_cannot_be_billed_again(): void
    {
        [$shop, $owner] = $this->createShopWithOwner();
        $this->grantFeature($shop, 'restaurant_tables');
        [, $order, $item1, $item2] = $this->openOrderWithTwoItems($shop->id);

        $this->actingAs($owner, 'web')->post("/app/restaurant/orders/{$order->id}/bill", [
            'item_ids' => [$item1->id],
            'payments' => [['method' => 'cash', 'amount' => 200]],
        ])->assertRedirect();

        // trying to bill item1 again (already billed) alongside item2
        $response = $this->actingAs($owner, 'web')->post("/app/restaurant/orders/{$order->id}/bill", [
            'item_ids' => [$item1->id, $item2->id],
            'payments' => [],
        ]);

        $response->assertStatus(422);
        $this->assertSame(1, Sale::count()); // no second sale created
    }

    public function test_cancelling_after_a_partial_split_bill_only_restores_stock_for_what_was_still_open(): void
    {
        [$shop, $owner] = $this->createShopWithOwner();
        $this->grantFeature($shop, 'restaurant_tables');
        [$table, $order, $item1, $item2, $biryani, $cola] = $this->openOrderWithTwoItems($shop->id);

        $this->actingAs($owner, 'web')->post("/app/restaurant/orders/{$order->id}/bill", [
            'item_ids' => [$item1->id],
            'payments' => [['method' => 'cash', 'amount' => 200]],
        ])->assertRedirect();

        $this->actingAs($owner, 'web')->post("/app/restaurant/orders/{$order->id}/cancel")->assertRedirect();

        $this->assertSame('cancelled', $order->fresh()->status);
        $this->assertEquals(9, (float) $biryani->fresh()->stock); // sold, not given back
        $this->assertEquals(10, (float) $cola->fresh()->stock); // was still open, given back
    }

    public function test_split_billed_sale_carries_its_own_table_order_context(): void
    {
        [$shop, $owner] = $this->createShopWithOwner();
        $this->grantFeature($shop, 'restaurant_tables');
        [, $order, $item1] = $this->openOrderWithTwoItems($shop->id);
        $order->update(['waiter_name' => 'Rahim']);

        $this->actingAs($owner, 'web')->post("/app/restaurant/orders/{$order->id}/bill", [
            'item_ids' => [$item1->id],
            'payments' => [['method' => 'cash', 'amount' => 200]],
        ])->assertRedirect();

        $sale = Sale::first();
        $response = $this->actingAs($owner, 'web')->get("/app/sales/{$sale->id}");
        $response->assertOk()->assertInertia(fn ($page) => $page
            ->where('sale.table_order.waiter_name', 'Rahim')
        );
    }
}
