<?php

namespace Tests\Feature;

use App\Console\Commands\SendLowStockAlerts;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Notifications\LowStockAlert;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\Concerns\CreatesShops;
use Tests\TestCase;

class LowStockAlertTest extends TestCase
{
    use RefreshDatabase, CreatesShops;

    public function test_notifies_owner_when_stock_drops_to_or_below_reorder_point(): void
    {
        Notification::fake();
        [$shop, $owner] = $this->createShopWithOwner(['status' => 'active']);
        $this->grantFeature($shop, 'low_stock_alerts');
        $low = Product::create(['shop_id' => $shop->id, 'name' => 'Rice', 'cost' => 10, 'price' => 20, 'stock' => 3, 'reorder_point' => 5]);
        $fine = Product::create(['shop_id' => $shop->id, 'name' => 'Oil', 'cost' => 10, 'price' => 20, 'stock' => 20, 'reorder_point' => 5]);
        $unconfigured = Product::create(['shop_id' => $shop->id, 'name' => 'Salt', 'cost' => 10, 'price' => 20, 'stock' => 1, 'reorder_point' => null]);

        $this->artisan(SendLowStockAlerts::class)->assertSuccessful();

        Notification::assertSentTo($owner, LowStockAlert::class, fn ($n) => $n->toDatabase($owner)['product_id'] === $low->id);
        Notification::assertSentToTimes($owner, LowStockAlert::class, 1);
    }

    public function test_shop_without_the_feature_is_never_notified(): void
    {
        Notification::fake();
        [$shop, $owner] = $this->createShopWithOwner(['status' => 'active']);
        // feature deliberately not granted
        Product::create(['shop_id' => $shop->id, 'name' => 'Rice', 'cost' => 10, 'price' => 20, 'stock' => 1, 'reorder_point' => 5]);

        $this->artisan(SendLowStockAlerts::class)->assertSuccessful();

        Notification::assertNothingSentTo($owner);
    }

    public function test_the_same_product_is_never_alerted_twice_in_one_day(): void
    {
        [$shop, $owner] = $this->createShopWithOwner(['status' => 'active']);
        $this->grantFeature($shop, 'low_stock_alerts');
        Product::create(['shop_id' => $shop->id, 'name' => 'Rice', 'cost' => 10, 'price' => 20, 'stock' => 1, 'reorder_point' => 5]);

        $this->artisan(SendLowStockAlerts::class)->assertSuccessful();
        $this->artisan(SendLowStockAlerts::class)->assertSuccessful();

        $this->assertSame(1, $owner->fresh()->notifications()->where('type', LowStockAlert::class)->count());
    }

    /**
     * A specific color/size can run low while the product's own summed
     * stock still looks fine overall — must alert independently rather than
     * only ever checking the parent product's total.
     */
    public function test_notifies_owner_when_a_specific_variant_drops_to_or_below_its_own_reorder_point(): void
    {
        Notification::fake();
        [$shop, $owner] = $this->createShopWithOwner(['status' => 'active']);
        $this->grantFeature($shop, 'low_stock_alerts');
        $this->grantFeature($shop, 'product_variants');
        $product = Product::create(['shop_id' => $shop->id, 'name' => 'Shirt', 'cost' => 200, 'price' => 400, 'stock' => 30, 'reorder_point' => null]);
        $lowVariant = ProductVariant::create(['shop_id' => $shop->id, 'product_id' => $product->id, 'size' => 'M', 'color' => 'Blue', 'stock' => 2, 'reorder_point' => 5]);
        ProductVariant::create(['shop_id' => $shop->id, 'product_id' => $product->id, 'size' => 'L', 'color' => 'Blue', 'stock' => 28, 'reorder_point' => 5]);

        $this->artisan(SendLowStockAlerts::class)->assertSuccessful();

        Notification::assertSentTo($owner, LowStockAlert::class, fn ($n) => $n->toDatabase($owner)['product_variant_id'] === $lowVariant->id);
        Notification::assertSentToTimes($owner, LowStockAlert::class, 1); // only the low variant, not the fine one, and the product itself has no reorder_point set
    }

    public function test_the_same_variant_is_never_alerted_twice_in_one_day(): void
    {
        [$shop, $owner] = $this->createShopWithOwner(['status' => 'active']);
        $this->grantFeature($shop, 'low_stock_alerts');
        $this->grantFeature($shop, 'product_variants');
        $product = Product::create(['shop_id' => $shop->id, 'name' => 'Shirt', 'cost' => 200, 'price' => 400, 'stock' => 2]);
        ProductVariant::create(['shop_id' => $shop->id, 'product_id' => $product->id, 'size' => 'M', 'stock' => 2, 'reorder_point' => 5]);

        $this->artisan(SendLowStockAlerts::class)->assertSuccessful();
        $this->artisan(SendLowStockAlerts::class)->assertSuccessful();

        $this->assertSame(1, $owner->fresh()->notifications()->where('type', LowStockAlert::class)->count());
    }
}
