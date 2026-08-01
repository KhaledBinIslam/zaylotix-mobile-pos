<?php

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesShops;
use Tests\TestCase;

class ActivityLogTest extends TestCase
{
    use RefreshDatabase, CreatesShops;

    public function test_stock_in_writes_an_activity_log_entry(): void
    {
        [$shop, $owner] = $this->createShopWithOwner();
        $product = Product::create(['shop_id' => $shop->id, 'name' => 'Rice', 'cost' => 10, 'price' => 20, 'stock' => 5]);

        $this->actingAs($owner, 'web')->post("/app/products/{$product->id}/stock-in", [
            'qty' => 10, 'cost' => 12,
        ])->assertRedirect();

        $this->assertSame(1, ActivityLog::count());
        $this->assertSame('product.stockIn', ActivityLog::first()->action);
        $this->assertSame($owner->id, ActivityLog::first()->user_id);
    }

    public function test_activity_log_is_tenant_scoped(): void
    {
        [$shopA, $ownerA] = $this->createShopWithOwner();
        [$shopB, $ownerB] = $this->createShopWithOwner();
        $productA = Product::create(['shop_id' => $shopA->id, 'name' => 'Rice', 'cost' => 10, 'price' => 20, 'stock' => 5]);
        $productB = Product::create(['shop_id' => $shopB->id, 'name' => 'Oil', 'cost' => 10, 'price' => 20, 'stock' => 5]);

        $this->actingAs($ownerA, 'web')->post("/app/products/{$productA->id}/stock-in", ['qty' => 5]);
        $this->actingAs($ownerB, 'web')->post("/app/products/{$productB->id}/stock-in", ['qty' => 5]);

        $this->grantFeature($shopA, 'activity_log');
        $response = $this->actingAs($ownerA, 'web')->get('/app/activity');

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page->has('logs.data', 1));
    }

    public function test_cashier_cannot_reach_the_activity_log_even_if_the_shop_has_the_feature(): void
    {
        [$shop, $owner] = $this->createShopWithOwner();
        $this->grantFeature($shop, 'activity_log');
        $cashier = \App\Models\User::create([
            'shop_id' => $shop->id, 'name' => 'Cashier', 'phone' => '01900006666',
            'password' => 'secret1', 'role' => 'staff', 'permissions' => ['pos', 'stock'], 'lang' => 'bn',
        ]);

        $this->actingAs($cashier, 'web')->get('/app/activity')->assertForbidden();
    }
}
