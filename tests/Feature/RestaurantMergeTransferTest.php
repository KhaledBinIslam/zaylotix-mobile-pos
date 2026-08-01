<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\RestaurantTable;
use App\Models\TableOrder;
use App\Models\TableOrderItem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesShops;
use Tests\TestCase;

class RestaurantMergeTransferTest extends TestCase
{
    use RefreshDatabase, CreatesShops;

    private function openOrder(int $shopId, string $tableName): array
    {
        $table = RestaurantTable::create(['shop_id' => $shopId, 'name' => $tableName, 'status' => 'occupied']);
        $order = TableOrder::create(['shop_id' => $shopId, 'restaurant_table_id' => $table->id, 'status' => 'open', 'opened_at' => now()]);

        return [$table, $order];
    }

    public function test_transferring_moves_the_order_to_a_free_table(): void
    {
        [$shop, $owner] = $this->createShopWithOwner();
        $this->grantFeature($shop, 'restaurant_tables');
        [$fromTable, $order] = $this->openOrder($shop->id, 'T1');
        $toTable = RestaurantTable::create(['shop_id' => $shop->id, 'name' => 'T2', 'status' => 'free']);

        $this->actingAs($owner, 'web')->post("/app/restaurant/tables/{$fromTable->id}/transfer", [
            'to_table_id' => $toTable->id,
        ])->assertRedirect();

        $this->assertSame($toTable->id, $order->fresh()->restaurant_table_id);
        $this->assertSame('free', $fromTable->fresh()->status);
        $this->assertSame('occupied', $toTable->fresh()->status);
    }

    public function test_cannot_transfer_onto_an_already_occupied_table(): void
    {
        [$shop, $owner] = $this->createShopWithOwner();
        $this->grantFeature($shop, 'restaurant_tables');
        [$fromTable, $order] = $this->openOrder($shop->id, 'T1');
        [$toTable, $otherOrder] = $this->openOrder($shop->id, 'T2'); // already occupied

        $response = $this->actingAs($owner, 'web')->post("/app/restaurant/tables/{$fromTable->id}/transfer", [
            'to_table_id' => $toTable->id,
        ]);

        $response->assertStatus(422);
        $this->assertSame($fromTable->id, $order->fresh()->restaurant_table_id);
    }

    public function test_cannot_transfer_a_table_with_no_open_order(): void
    {
        [$shop, $owner] = $this->createShopWithOwner();
        $this->grantFeature($shop, 'restaurant_tables');
        $emptyTable = RestaurantTable::create(['shop_id' => $shop->id, 'name' => 'T1', 'status' => 'free']);
        $toTable = RestaurantTable::create(['shop_id' => $shop->id, 'name' => 'T2', 'status' => 'free']);

        $this->actingAs($owner, 'web')->post("/app/restaurant/tables/{$emptyTable->id}/transfer", [
            'to_table_id' => $toTable->id,
        ])->assertStatus(422);
    }

    public function test_merging_moves_every_open_item_into_the_target_order_and_frees_the_source_table(): void
    {
        [$shop, $owner] = $this->createShopWithOwner();
        $this->grantFeature($shop, 'restaurant_tables');
        [$tableA, $orderA] = $this->openOrder($shop->id, 'T1');
        [$tableB, $orderB] = $this->openOrder($shop->id, 'T2');
        $product = Product::create(['shop_id' => $shop->id, 'name' => 'Biryani', 'cost' => 100, 'price' => 200, 'stock' => 10]);
        $itemA = TableOrderItem::create(['shop_id' => $shop->id, 'table_order_id' => $orderA->id, 'product_id' => $product->id, 'product_name' => 'Biryani', 'qty' => 1, 'price' => 200, 'cost' => 100]);
        $orderA->update(['kitchen_note' => 'ঝাল কম']);
        $orderB->update(['kitchen_note' => 'লবণ বেশি']);

        $this->actingAs($owner, 'web')->post("/app/restaurant/tables/{$tableB->id}/merge", [
            'from_table_id' => $tableA->id,
        ])->assertRedirect();

        $this->assertSame($orderB->id, $itemA->fresh()->table_order_id);
        $this->assertSame('merged', $orderA->fresh()->status);
        $this->assertSame('free', $tableA->fresh()->status);
        $this->assertSame('occupied', $tableB->fresh()->status); // unchanged, still occupied
        $this->assertStringContainsString('ঝাল কম', $orderB->fresh()->kitchen_note);
        $this->assertStringContainsString('লবণ বেশি', $orderB->fresh()->kitchen_note);
    }

    public function test_cannot_merge_a_table_that_has_no_open_order(): void
    {
        [$shop, $owner] = $this->createShopWithOwner();
        $this->grantFeature($shop, 'restaurant_tables');
        [$tableB, $orderB] = $this->openOrder($shop->id, 'T2');
        $emptyTable = RestaurantTable::create(['shop_id' => $shop->id, 'name' => 'T1', 'status' => 'free']);

        $this->actingAs($owner, 'web')->post("/app/restaurant/tables/{$tableB->id}/merge", [
            'from_table_id' => $emptyTable->id,
        ])->assertStatus(422);
    }

    public function test_transfer_and_merge_are_tenant_scoped(): void
    {
        [$shopA, $ownerA] = $this->createShopWithOwner();
        $this->grantFeature($shopA, 'restaurant_tables');
        [$shopB] = $this->createShopWithOwner();
        [$tableB1, $orderB1] = $this->openOrder($shopB->id, 'B1');
        $tableB2 = RestaurantTable::create(['shop_id' => $shopB->id, 'name' => 'B2', 'status' => 'free']);

        $this->actingAs($ownerA, 'web')->post("/app/restaurant/tables/{$tableB1->id}/transfer", [
            'to_table_id' => $tableB2->id,
        ])->assertNotFound();

        $this->assertSame($tableB1->id, $orderB1->fresh()->restaurant_table_id);
    }
}
