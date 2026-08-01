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
 * Waiter tracking (table_orders.waiter_name) and the delivery-platform/
 * waiter-wise sales breakdown Reports::restaurantBreakdown() builds from it.
 */
class RestaurantReportBreakdownTest extends TestCase
{
    use RefreshDatabase, CreatesShops;

    private function billedSale(\App\Models\User $owner, int $shopId, ?string $waiter, string $source, ?string $platform, float $total): void
    {
        $table = RestaurantTable::create(['shop_id' => $shopId, 'name' => 'T-'.uniqid(), 'status' => 'occupied']);
        $order = TableOrder::create([
            'shop_id' => $shopId, 'restaurant_table_id' => $table->id, 'status' => 'open', 'opened_at' => now(),
            'waiter_name' => $waiter, 'order_source' => $source, 'delivery_platform' => $platform,
        ]);
        $product = Product::create(['shop_id' => $shopId, 'name' => 'Item', 'cost' => 1, 'price' => $total, 'stock' => 10]);
        TableOrderItem::create(['shop_id' => $shopId, 'table_order_id' => $order->id, 'product_id' => $product->id, 'product_name' => 'Item', 'qty' => 1, 'price' => $total, 'cost' => 1]);
        $product->decrement('stock', 1);

        $this->actingAs($owner, 'web')->post("/app/restaurant/orders/{$order->id}/bill", [
            'payments' => [['method' => 'cash', 'amount' => $total]],
        ])->assertRedirect();
    }

    public function test_owner_can_set_a_waiter_name_on_the_order(): void
    {
        [$shop, $owner] = $this->createShopWithOwner();
        $this->grantFeature($shop, 'restaurant_tables');
        $table = RestaurantTable::create(['shop_id' => $shop->id, 'name' => 'T1', 'status' => 'occupied']);
        $order = TableOrder::create(['shop_id' => $shop->id, 'restaurant_table_id' => $table->id, 'status' => 'open', 'opened_at' => now()]);

        $this->actingAs($owner, 'web')->patch("/app/restaurant/orders/{$order->id}/meta", [
            'order_source' => 'dine_in', 'waiter_name' => 'Karim',
        ])->assertRedirect();

        $this->assertSame('Karim', $order->fresh()->waiter_name);
    }

    public function test_restaurant_breakdown_groups_by_delivery_platform_and_waiter(): void
    {
        [$shop, $owner] = $this->createShopWithOwner();
        $this->grantFeature($shop, 'restaurant_tables');
        $this->grantFeature($shop, 'reports');

        $this->billedSale($owner, $shop->id, 'Karim', 'delivery', 'Food Panda', 200);
        $this->billedSale($owner, $shop->id, 'Karim', 'delivery', 'Food Panda', 150);
        $this->billedSale($owner, $shop->id, 'Rahim', 'dine_in', null, 500);
        $this->billedSale($owner, $shop->id, null, 'takeaway', null, 100);

        $response = $this->actingAs($owner, 'web')->get('/app/reports?preset=today');

        $response->assertOk()->assertInertia(fn ($page) => $page
            ->where('restaurantBreakdown.by_source.Food Panda.total', 350)
            ->where('restaurantBreakdown.by_source.Food Panda.count', 2)
            ->where('restaurantBreakdown.by_source.ডাইন-ইন.total', 500)
            ->where('restaurantBreakdown.by_source.টেকঅ্যাওয়ে.total', 100)
            ->where('restaurantBreakdown.by_waiter.Karim.total', 350)
            ->where('restaurantBreakdown.by_waiter.Rahim.total', 500)
        );
    }

    public function test_restaurant_breakdown_is_null_without_the_feature(): void
    {
        [$shop, $owner] = $this->createShopWithOwner(); // no restaurant_tables feature
        $this->grantFeature($shop, 'reports');

        $response = $this->actingAs($owner, 'web')->get('/app/reports?preset=today');

        $response->assertOk()->assertInertia(fn ($page) => $page->where('restaurantBreakdown', null));
    }
}
