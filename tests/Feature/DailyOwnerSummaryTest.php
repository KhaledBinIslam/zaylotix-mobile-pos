<?php

namespace Tests\Feature;

use App\Console\Commands\SendDailyOwnerSummary;
use App\Models\Customer;
use App\Models\Product;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Notifications\DailyOwnerSummary;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\Concerns\CreatesShops;
use Tests\TestCase;

/**
 * "দৈনিক মালিক সারাংশ" — see docs/FIX_PLAN.md's design note. No new
 * source of truth: every figure comes straight from Reports:: (same
 * helper every report page already uses) and the same low-stock query
 * SendLowStockAlerts already runs.
 */
class DailyOwnerSummaryTest extends TestCase
{
    use RefreshDatabase, CreatesShops;

    private function sellToday(int $shopId, int $productId, string $name, float $qty, float $price): void
    {
        $sale = Sale::create(['shop_id' => $shopId, 'invoice_no' => 'INV-'.uniqid(), 'subtotal' => $price * $qty, 'discount' => 0, 'total' => $price * $qty, 'profit' => $price * $qty / 2, 'date' => now()->toDateString(), 'time' => '12:00:00']);
        SaleItem::create(['shop_id' => $shopId, 'sale_id' => $sale->id, 'product_id' => $productId, 'product_name' => $name, 'qty' => $qty, 'price' => $price, 'discount' => 0, 'cost' => $price / 2]);
    }

    public function test_notifies_owner_with_a_database_notification_when_feature_is_on(): void
    {
        Notification::fake();
        [$shop, $owner] = $this->createShopWithOwner(['status' => 'active', 'phone' => '01712345678']);
        $this->grantFeature($shop, 'daily_owner_summary');
        $product = Product::create(['shop_id' => $shop->id, 'name' => 'Rice', 'cost' => 50, 'price' => 100, 'stock' => 10]);
        $this->sellToday($shop->id, $product->id, 'Rice', 2, 100);

        $this->artisan(SendDailyOwnerSummary::class)->assertSuccessful();

        Notification::assertSentTo($owner, DailyOwnerSummary::class, function ($n) use ($owner) {
            $data = $n->toDatabase($owner);

            return str_contains($data['message'], 'Rice')
                && str_contains($data['message'], 'বিক্রি')
                && $data['whatsapp_url'] !== null
                && str_starts_with($data['whatsapp_url'], 'https://wa.me/8801712345678');
        });
    }

    public function test_shop_without_the_feature_is_never_notified(): void
    {
        Notification::fake();
        [$shop, $owner] = $this->createShopWithOwner(['status' => 'active']);
        // feature deliberately not granted

        $this->artisan(SendDailyOwnerSummary::class)->assertSuccessful();

        Notification::assertNothingSentTo($owner);
    }

    public function test_never_sent_twice_in_one_day(): void
    {
        [$shop, $owner] = $this->createShopWithOwner(['status' => 'active']);
        $this->grantFeature($shop, 'daily_owner_summary');

        $this->artisan(SendDailyOwnerSummary::class)->assertSuccessful();
        $this->artisan(SendDailyOwnerSummary::class)->assertSuccessful();

        $this->assertSame(1, $owner->fresh()->notifications()->where('type', DailyOwnerSummary::class)->count());
    }

    public function test_includes_low_stock_count_and_total_due(): void
    {
        Notification::fake();
        [$shop, $owner] = $this->createShopWithOwner(['status' => 'active']);
        $this->grantFeature($shop, 'daily_owner_summary');
        Product::create(['shop_id' => $shop->id, 'name' => 'Low Item', 'cost' => 10, 'price' => 20, 'stock' => 2, 'reorder_point' => 5]);
        Customer::create(['shop_id' => $shop->id, 'name' => 'Karim', 'phone' => '01700000001', 'due' => 500]);

        $this->artisan(SendDailyOwnerSummary::class)->assertSuccessful();

        Notification::assertSentTo($owner, DailyOwnerSummary::class, function ($n) use ($owner) {
            $message = $n->toDatabase($owner)['message'];

            return str_contains($message, 'কম স্টকে') && str_contains($message, 'মোট বাকি');
        });
    }

    /**
     * "Online" block — see docs/FIX_PLAN.md's Phase 37 design note,
     * Khaled's own earlier ask that the owner summary say something
     * about online sales.
     */
    public function test_includes_an_online_block_when_todays_online_activity_exists(): void
    {
        Notification::fake();
        [$shop, $owner] = $this->createShopWithOwner(['status' => 'active']);
        $this->grantFeature($shop, 'daily_owner_summary');
        $this->grantFeature($shop, 'online_business');
        $channel = \App\Models\SalesChannel::create(['shop_id' => $shop->id, 'name' => 'Page A', 'platform_type' => 'facebook', 'order_prefix' => 'FB-']);
        $product = Product::create(['shop_id' => $shop->id, 'name' => 'Rice', 'cost' => 50, 'price' => 100, 'stock' => 10]);

        $this->actingAs($owner, 'web')->post(route('app.onlineOrders.store'), [
            'channel_id' => $channel->id, 'customer_phone' => '01811111111',
            'items' => [['product_id' => $product->id, 'qty' => 1]], 'cod_amount' => 100,
        ])->assertRedirect();
        $order = \App\Models\OnlineOrder::first();
        foreach (['confirmed', 'packed', 'handed_to_courier', 'in_transit'] as $status) {
            $this->actingAs($owner, 'web')->patch(route('app.onlineOrders.transition', $order->id), ['status' => $status])->assertRedirect();
        }
        $this->actingAs($owner, 'web')->post(route('app.onlineOrders.deliver', $order->id))->assertRedirect();

        $this->artisan(SendDailyOwnerSummary::class)->assertSuccessful();

        Notification::assertSentTo($owner, DailyOwnerSummary::class, function ($n) use ($owner) {
            $message = $n->toDatabase($owner)['message'];

            return str_contains($message, 'অনলাইন') && str_contains($message, '1টা অর্ডার এসেছে') && str_contains($message, '1টা ডেলিভারড');
        });
    }

    public function test_online_block_is_absent_without_the_feature(): void
    {
        Notification::fake();
        [$shop, $owner] = $this->createShopWithOwner(['status' => 'active']);
        $this->grantFeature($shop, 'daily_owner_summary');
        // online_business feature deliberately not granted

        $this->artisan(SendDailyOwnerSummary::class)->assertSuccessful();

        Notification::assertSentTo($owner, DailyOwnerSummary::class, fn ($n) => ! str_contains($n->toDatabase($owner)['message'], 'অনলাইন'));
    }

    public function test_no_whatsapp_url_when_owner_has_no_phone_on_file(): void
    {
        Notification::fake();
        [$shop, $owner] = $this->createShopWithOwner(['status' => 'active']);
        $owner->update(['phone' => null]);
        $this->grantFeature($shop, 'daily_owner_summary');

        $this->artisan(SendDailyOwnerSummary::class)->assertSuccessful();

        Notification::assertSentTo($owner, DailyOwnerSummary::class, fn ($n) => $n->toDatabase($owner)['whatsapp_url'] === null);
    }

    public function test_is_tenant_scoped_to_only_the_shops_own_sales_and_due(): void
    {
        Notification::fake();
        [$shopA, $ownerA] = $this->createShopWithOwner(['status' => 'active']);
        [$shopB, $ownerB] = $this->createShopWithOwner(['status' => 'active']);
        $this->grantFeature($shopA, 'daily_owner_summary');
        $this->grantFeature($shopB, 'daily_owner_summary');
        $productA = Product::create(['shop_id' => $shopA->id, 'name' => 'Shop A Product', 'cost' => 50, 'price' => 100, 'stock' => 10]);
        $this->sellToday($shopA->id, $productA->id, 'Shop A Product', 1, 100);

        $this->artisan(SendDailyOwnerSummary::class)->assertSuccessful();

        Notification::assertSentTo($ownerA, DailyOwnerSummary::class, fn ($n) => str_contains($n->toDatabase($ownerA)['message'], 'Shop A Product'));
        Notification::assertSentTo($ownerB, DailyOwnerSummary::class, fn ($n) => ! str_contains($n->toDatabase($ownerB)['message'], 'Shop A Product'));
    }
}
