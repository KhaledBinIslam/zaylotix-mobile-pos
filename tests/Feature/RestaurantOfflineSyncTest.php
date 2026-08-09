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
 * Covers the offline-sync-safety guarantees Order.vue's useOfflineActionQueue
 * relies on (see resources/js/composables/useOfflineActionQueue.js and
 * Order.vue's postItem()/submitBill()):
 *
 *  - addItem() and bill() are replayed against the SAME endpoints an online
 *    request would use, wrapped in DB::transaction() + lockForUpdate() on
 *    both the order/table row and the product row — so two queued actions
 *    from (simulated) different devices, synced back-to-back once
 *    connectivity returns, can never both succeed against stock that only
 *    covers one of them, and invoice numbers can never collide.
 *  - bill() now returns clean JSON ({sale_id}) instead of a redirect when
 *    the request declares Accept: application/json — what the offline
 *    queue's raw fetch() (and Order.vue's submitBill() on a successful
 *    online bill) actually sends, needed so the client can navigate
 *    straight to the new sale's receipt instead of an opaque redirect.
 */
class RestaurantOfflineSyncTest extends TestCase
{
    use RefreshDatabase, CreatesShops;

    private function openOrder(int $shopId): TableOrder
    {
        $table = RestaurantTable::create(['shop_id' => $shopId, 'name' => 'T1', 'status' => 'occupied']);

        return TableOrder::create(['shop_id' => $shopId, 'restaurant_table_id' => $table->id, 'status' => 'open', 'opened_at' => now()]);
    }

    public function test_bill_returns_json_with_sale_id_when_client_wants_json(): void
    {
        [$shop, $owner] = $this->createShopWithOwner();
        $this->grantFeature($shop, 'restaurant_tables');
        $order = $this->openOrder($shop->id);
        $product = Product::create(['shop_id' => $shop->id, 'name' => 'Biryani', 'cost' => 100, 'price' => 200, 'stock' => 10]);
        TableOrderItem::create(['shop_id' => $shop->id, 'table_order_id' => $order->id, 'product_id' => $product->id, 'product_name' => 'Biryani', 'qty' => 1, 'price' => 200, 'cost' => 100]);

        $response = $this->actingAs($owner, 'web')
            ->postJson("/app/restaurant/orders/{$order->id}/bill", [
                'payments' => [['method' => 'cash', 'amount' => 200]],
            ]);

        $response->assertOk()->assertJson(['sale_id' => Sale::first()->id]);
        // the normal (non-JSON) request keeps getting the redirect exactly
        // as before — the online in-app path via Inertia is untouched
        $this->assertNotNull(Sale::first());
    }

    public function test_bill_still_redirects_for_a_normal_non_json_request(): void
    {
        [$shop, $owner] = $this->createShopWithOwner();
        $this->grantFeature($shop, 'restaurant_tables');
        $order = $this->openOrder($shop->id);
        $product = Product::create(['shop_id' => $shop->id, 'name' => 'Cola', 'cost' => 10, 'price' => 30, 'stock' => 10]);
        TableOrderItem::create(['shop_id' => $shop->id, 'table_order_id' => $order->id, 'product_id' => $product->id, 'product_name' => 'Cola', 'qty' => 1, 'price' => 30, 'cost' => 10]);

        $response = $this->actingAs($owner, 'web')->post("/app/restaurant/orders/{$order->id}/bill", [
            'payments' => [['method' => 'cash', 'amount' => 30]],
        ]);

        $response->assertRedirect();
    }

    /**
     * Two "devices" each queue an addItem for the same scarce-stock product
     * while offline, then sync sequentially once back online (exactly how
     * useOfflineActionQueue.trySync() replays entries one at a time). The
     * first sync must succeed and decrement stock; the second must be
     * rejected 422 rather than oversell, and stock must land exactly at
     * zero — never negative, never double-counted.
     */
    public function test_sequential_offline_add_item_sync_never_oversells_stock(): void
    {
        [$shop, $owner] = $this->createShopWithOwner();
        $this->grantFeature($shop, 'restaurant_tables');
        $order = $this->openOrder($shop->id);
        $product = Product::create(['shop_id' => $shop->id, 'name' => 'Limited Cake', 'cost' => 50, 'price' => 100, 'stock' => 5]);

        // "device A" queued qty 5 while offline, syncs first
        $first = $this->actingAs($owner, 'web')->postJson("/app/restaurant/orders/{$order->id}/items", [
            'product_id' => $product->id,
            'qty' => 5,
        ]);
        $first->assertOk();
        $this->assertEquals(0, $product->fresh()->stock);

        // "device B" queued qty 1 while offline (unaware A would exhaust
        // stock first), syncs second — must be rejected, not oversold
        $second = $this->actingAs($owner, 'web')->postJson("/app/restaurant/orders/{$order->id}/items", [
            'product_id' => $product->id,
            'qty' => 1,
        ]);
        $second->assertStatus(422);
        $this->assertEquals(0, $product->fresh()->stock); // untouched by the rejected sync

        $this->assertSame(5, TableOrderItem::where('table_order_id', $order->id)->sum('qty'));
    }

    /**
     * Two orders billed back-to-back (the sequential-replay pattern the
     * offline queue uses) must never produce duplicate invoice numbers —
     * Shop::invoice_counter is locked (lockForUpdate) and incremented
     * inside the same transaction as the Sale it numbers.
     */
    public function test_sequential_offline_bill_sync_never_collides_invoice_numbers(): void
    {
        [$shop, $owner] = $this->createShopWithOwner();
        $this->grantFeature($shop, 'restaurant_tables');

        $orderA = $this->openOrder($shop->id);
        $productA = Product::create(['shop_id' => $shop->id, 'name' => 'A', 'cost' => 10, 'price' => 50, 'stock' => 10]);
        TableOrderItem::create(['shop_id' => $shop->id, 'table_order_id' => $orderA->id, 'product_id' => $productA->id, 'product_name' => 'A', 'qty' => 1, 'price' => 50, 'cost' => 10]);

        $orderB = $this->openOrder($shop->id);
        $productB = Product::create(['shop_id' => $shop->id, 'name' => 'B', 'cost' => 10, 'price' => 80, 'stock' => 10]);
        TableOrderItem::create(['shop_id' => $shop->id, 'table_order_id' => $orderB->id, 'product_id' => $productB->id, 'product_name' => 'B', 'qty' => 1, 'price' => 80, 'cost' => 10]);

        $resA = $this->actingAs($owner, 'web')->postJson("/app/restaurant/orders/{$orderA->id}/bill", [
            'payments' => [['method' => 'cash', 'amount' => 50]],
        ])->assertOk();
        $resB = $this->actingAs($owner, 'web')->postJson("/app/restaurant/orders/{$orderB->id}/bill", [
            'payments' => [['method' => 'cash', 'amount' => 80]],
        ])->assertOk();

        $invoiceA = Sale::find($resA->json('sale_id'))->invoice_no;
        $invoiceB = Sale::find($resB->json('sale_id'))->invoice_no;
        $this->assertNotSame($invoiceA, $invoiceB);
        $this->assertSame(2, Sale::count());
    }

    /**
     * A queued add-item that would out-of-stock reject, and one that comes
     * after a "bill" already closed the order, both fail the way the
     * client's offlineActions.trySync() expects (422, "stop the queue"
     * signal) — not a 500 / silently-accepted state.
     */
    public function test_add_item_after_order_already_billed_is_rejected_not_silently_applied(): void
    {
        [$shop, $owner] = $this->createShopWithOwner();
        $this->grantFeature($shop, 'restaurant_tables');
        $order = $this->openOrder($shop->id);
        $product = Product::create(['shop_id' => $shop->id, 'name' => 'Rice', 'cost' => 20, 'price' => 60, 'stock' => 10]);
        TableOrderItem::create(['shop_id' => $shop->id, 'table_order_id' => $order->id, 'product_id' => $product->id, 'product_name' => 'Rice', 'qty' => 1, 'price' => 60, 'cost' => 20]);
        $product->decrement('stock', 1); // items created directly (not via addItem()) must mirror addItem()'s at-add-time decrement

        $this->actingAs($owner, 'web')->postJson("/app/restaurant/orders/{$order->id}/bill", [
            'payments' => [['method' => 'cash', 'amount' => 60]],
        ])->assertOk();

        // a second device's queued "add another item" for the now-billed
        // order syncs after — must be rejected, never silently attached
        $late = $this->actingAs($owner, 'web')->postJson("/app/restaurant/orders/{$order->id}/items", [
            'product_id' => $product->id,
            'qty' => 1,
        ]);
        $late->assertStatus(422);
        $this->assertEquals(9, $product->fresh()->stock); // only the billed item's stock ever moved
    }
}
