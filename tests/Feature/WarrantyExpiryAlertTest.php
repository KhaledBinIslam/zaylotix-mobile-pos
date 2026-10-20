<?php

namespace Tests\Feature;

use App\Console\Commands\SendWarrantyExpiryAlerts;
use App\Models\Product;
use App\Models\ProductSerial;
use App\Notifications\WarrantyExpiringSoon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\Concerns\CreatesShops;
use Tests\TestCase;

/**
 * Phase 3 feature 9 — "ধরন-ভিত্তিক সুবিধা" (mobile/electronics vertical, see
 * docs/FIX_PLAN.md's design note). Mirrors ExpiryAlertTest's own
 * tiered/dedup-by-tier assertions exactly, just against a sold unit's
 * warranty_expiry.
 */
class WarrantyExpiryAlertTest extends TestCase
{
    use RefreshDatabase, CreatesShops;

    public function test_notifies_owner_at_the_tightest_tier_a_sold_unit_currently_falls_into(): void
    {
        Notification::fake();
        [$shop, $owner] = $this->createShopWithOwner(['status' => 'active']);
        $this->grantFeature($shop, 'warranty_expiry_alerts');
        $product = Product::create(['shop_id' => $shop->id, 'name' => 'Phone X', 'cost' => 10000, 'price' => 12000, 'stock' => 5, 'track_serial' => true]);
        $near = ProductSerial::create(['shop_id' => $shop->id, 'product_id' => $product->id, 'imei' => '1111', 'status' => 'sold', 'warranty_expiry' => now()->addDays(3)]);
        $mid = ProductSerial::create(['shop_id' => $shop->id, 'product_id' => $product->id, 'imei' => '2222', 'status' => 'sold', 'warranty_expiry' => now()->addDays(20)]);
        ProductSerial::create(['shop_id' => $shop->id, 'product_id' => $product->id, 'imei' => '3333', 'status' => 'sold', 'warranty_expiry' => now()->addDays(200)]); // outside every tier
        ProductSerial::create(['shop_id' => $shop->id, 'product_id' => $product->id, 'imei' => '4444', 'status' => 'in_stock', 'warranty_expiry' => now()->addDays(3)]); // never sold, must not alert

        $this->artisan(SendWarrantyExpiryAlerts::class)->assertSuccessful();

        Notification::assertSentTo($owner, WarrantyExpiringSoon::class, fn ($n) => $n->toDatabase($owner)['product_serial_id'] === $near->id && $n->toDatabase($owner)['tier'] === 7);
        Notification::assertSentTo($owner, WarrantyExpiringSoon::class, fn ($n) => $n->toDatabase($owner)['product_serial_id'] === $mid->id && $n->toDatabase($owner)['tier'] === 30);
        Notification::assertSentToTimes($owner, WarrantyExpiringSoon::class, 2);
    }

    public function test_an_already_expired_warranty_gets_the_zero_tier_alert(): void
    {
        Notification::fake();
        [$shop, $owner] = $this->createShopWithOwner(['status' => 'active']);
        $this->grantFeature($shop, 'warranty_expiry_alerts');
        $product = Product::create(['shop_id' => $shop->id, 'name' => 'Phone X', 'cost' => 10000, 'price' => 12000, 'stock' => 5, 'track_serial' => true]);
        $expired = ProductSerial::create(['shop_id' => $shop->id, 'product_id' => $product->id, 'imei' => '1111', 'status' => 'sold', 'warranty_expiry' => now()->subDays(3)]);

        $this->artisan(SendWarrantyExpiryAlerts::class)->assertSuccessful();

        Notification::assertSentTo($owner, WarrantyExpiringSoon::class, fn ($n) => $n->toDatabase($owner)['product_serial_id'] === $expired->id && $n->toDatabase($owner)['tier'] === 0);
    }

    public function test_shop_without_the_feature_is_never_notified(): void
    {
        Notification::fake();
        [$shop, $owner] = $this->createShopWithOwner(['status' => 'active']); // feature not granted
        $product = Product::create(['shop_id' => $shop->id, 'name' => 'Phone X', 'cost' => 10000, 'price' => 12000, 'stock' => 5, 'track_serial' => true]);
        ProductSerial::create(['shop_id' => $shop->id, 'product_id' => $product->id, 'imei' => '1111', 'status' => 'sold', 'warranty_expiry' => now()->addDays(3)]);

        $this->artisan(SendWarrantyExpiryAlerts::class)->assertSuccessful();

        Notification::assertNothingSentTo($owner);
    }

    public function test_the_same_serial_is_never_alerted_twice_for_the_same_tier(): void
    {
        [$shop, $owner] = $this->createShopWithOwner(['status' => 'active']);
        $this->grantFeature($shop, 'warranty_expiry_alerts');
        $product = Product::create(['shop_id' => $shop->id, 'name' => 'Phone X', 'cost' => 10000, 'price' => 12000, 'stock' => 5, 'track_serial' => true]);
        ProductSerial::create(['shop_id' => $shop->id, 'product_id' => $product->id, 'imei' => '1111', 'status' => 'sold', 'warranty_expiry' => now()->addDays(3)]);

        $this->artisan(SendWarrantyExpiryAlerts::class)->assertSuccessful();
        $this->artisan(SendWarrantyExpiryAlerts::class)->assertSuccessful();

        $this->assertSame(1, $owner->fresh()->notifications()->where('type', WarrantyExpiringSoon::class)->count());
    }
}
