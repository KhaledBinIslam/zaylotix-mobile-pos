<?php

namespace Tests\Feature;

use App\Console\Commands\SendExpiryAlerts;
use App\Models\Product;
use App\Models\ProductBatch;
use App\Notifications\ExpiryAlert;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\Concerns\CreatesShops;
use Tests\TestCase;

/**
 * Expiry alerts fire at 90/60/30/0-day tiers — once per batch per tier
 * actually crossed, not a repeat every day the batch happens to still be
 * within a threshold (that would bury the owner in duplicate notifications
 * for the same batch for a month straight).
 */
class ExpiryAlertTest extends TestCase
{
    use RefreshDatabase, CreatesShops;

    public function test_notifies_owner_at_the_tightest_tier_a_batch_currently_falls_into(): void
    {
        Notification::fake();
        [$shop, $owner] = $this->createShopWithOwner(['status' => 'active']);
        $this->grantFeature($shop, 'batch_tracking');
        $product = Product::create(['shop_id' => $shop->id, 'name' => 'Napa', 'cost' => 1, 'price' => 2, 'stock' => 30]);
        $near = ProductBatch::create(['shop_id' => $shop->id, 'product_id' => $product->id, 'qty' => 10, 'expiry_date' => now()->addDays(10)]);
        $mid = ProductBatch::create(['shop_id' => $shop->id, 'product_id' => $product->id, 'qty' => 10, 'expiry_date' => now()->addDays(45)]);
        $far = ProductBatch::create(['shop_id' => $shop->id, 'product_id' => $product->id, 'qty' => 10, 'expiry_date' => now()->addDays(200)]); // outside every tier
        ProductBatch::create(['shop_id' => $shop->id, 'product_id' => $product->id, 'qty' => 0, 'expiry_date' => now()->addDays(5)]); // depleted, must not alert

        $this->artisan(SendExpiryAlerts::class)->assertSuccessful();

        Notification::assertSentTo($owner, ExpiryAlert::class, fn ($n) => $n->toDatabase($owner)['product_batch_id'] === $near->id && $n->toDatabase($owner)['tier'] === 30);
        Notification::assertSentTo($owner, ExpiryAlert::class, fn ($n) => $n->toDatabase($owner)['product_batch_id'] === $mid->id && $n->toDatabase($owner)['tier'] === 60);
        Notification::assertSentToTimes($owner, ExpiryAlert::class, 2); // not $far (200 days out), not the depleted one
    }

    public function test_an_already_expired_batch_gets_the_zero_tier_alert(): void
    {
        Notification::fake();
        [$shop, $owner] = $this->createShopWithOwner(['status' => 'active']);
        $this->grantFeature($shop, 'batch_tracking');
        $product = Product::create(['shop_id' => $shop->id, 'name' => 'Napa', 'cost' => 1, 'price' => 2, 'stock' => 10]);
        $expired = ProductBatch::create(['shop_id' => $shop->id, 'product_id' => $product->id, 'qty' => 10, 'expiry_date' => now()->subDays(3)]);

        $this->artisan(SendExpiryAlerts::class)->assertSuccessful();

        Notification::assertSentTo($owner, ExpiryAlert::class, fn ($n) => $n->toDatabase($owner)['product_batch_id'] === $expired->id && $n->toDatabase($owner)['tier'] === 0);
    }

    public function test_shop_without_the_feature_is_never_notified(): void
    {
        Notification::fake();
        [$shop, $owner] = $this->createShopWithOwner(['status' => 'active']); // feature not granted
        $product = Product::create(['shop_id' => $shop->id, 'name' => 'Napa', 'cost' => 1, 'price' => 2, 'stock' => 10]);
        ProductBatch::create(['shop_id' => $shop->id, 'product_id' => $product->id, 'qty' => 10, 'expiry_date' => now()->addDays(5)]);

        $this->artisan(SendExpiryAlerts::class)->assertSuccessful();

        Notification::assertNothingSentTo($owner);
    }

    public function test_the_same_batch_is_never_alerted_twice_for_the_same_tier(): void
    {
        [$shop, $owner] = $this->createShopWithOwner(['status' => 'active']);
        $this->grantFeature($shop, 'batch_tracking');
        $product = Product::create(['shop_id' => $shop->id, 'name' => 'Napa', 'cost' => 1, 'price' => 2, 'stock' => 10]);
        ProductBatch::create(['shop_id' => $shop->id, 'product_id' => $product->id, 'qty' => 10, 'expiry_date' => now()->addDays(5)]);

        $this->artisan(SendExpiryAlerts::class)->assertSuccessful();
        $this->artisan(SendExpiryAlerts::class)->assertSuccessful();

        $this->assertSame(1, $owner->fresh()->notifications()->where('type', ExpiryAlert::class)->count());
    }

    public function test_a_batch_gets_a_fresh_alert_when_it_crosses_into_a_tighter_tier_later(): void
    {
        [$shop, $owner] = $this->createShopWithOwner(['status' => 'active']);
        $this->grantFeature($shop, 'batch_tracking');
        $product = Product::create(['shop_id' => $shop->id, 'name' => 'Napa', 'cost' => 1, 'price' => 2, 'stock' => 10]);
        $batch = ProductBatch::create(['shop_id' => $shop->id, 'product_id' => $product->id, 'qty' => 10, 'expiry_date' => now()->addDays(55)]);

        $this->artisan(SendExpiryAlerts::class)->assertSuccessful(); // tier 60
        $this->assertSame(1, $owner->fresh()->notifications()->where('type', ExpiryAlert::class)->count());

        $batch->update(['expiry_date' => now()->addDays(25)]); // now within the tighter 30-day tier
        $this->artisan(SendExpiryAlerts::class)->assertSuccessful();

        $this->assertSame(2, $owner->fresh()->notifications()->where('type', ExpiryAlert::class)->count());
    }
}
