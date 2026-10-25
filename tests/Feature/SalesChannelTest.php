<?php

namespace Tests\Feature;

use App\Models\ChannelProductPrice;
use App\Models\Product;
use App\Models\SalesChannel;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesShops;
use Tests\TestCase;

/**
 * "Online Business" module, part 1 — sales channels + channel pricing (see
 * docs/FIX_PLAN.md's Phase 31 design note). Genuinely new concept, built
 * additively on top of the existing tenant-scoping/feature/permission
 * machinery — nothing here touches an existing sale, product, or report.
 */
class SalesChannelTest extends TestCase
{
    use RefreshDatabase, CreatesShops;

    private function setupShop(): array
    {
        [$shop, $owner] = $this->createShopWithOwner();
        $this->grantFeature($shop, 'online_business');

        return [$shop, $owner];
    }

    public function test_owner_can_create_a_channel(): void
    {
        [$shop, $owner] = $this->setupShop();

        $response = $this->actingAs($owner, 'web')->post(route('app.channels.store'), [
            'name' => 'Facebook Page 1', 'platform_type' => 'facebook', 'order_prefix' => 'FB1-',
        ]);

        $response->assertRedirect();
        $channel = SalesChannel::first();
        $this->assertSame('Facebook Page 1', $channel->name);
        $this->assertSame('facebook', $channel->platform_type);
        $this->assertSame('FB1-', $channel->order_prefix);
        $this->assertTrue($channel->active);
        $this->assertSame(1000, $channel->order_counter);
    }

    public function test_channel_creation_is_blocked_without_the_feature(): void
    {
        [$shop, $owner] = $this->createShopWithOwner(); // feature not granted

        $this->actingAs($owner, 'web')->post(route('app.channels.store'), [
            'name' => 'Facebook Page 1', 'platform_type' => 'facebook', 'order_prefix' => 'FB1-',
        ])->assertStatus(403);
    }

    public function test_staff_without_the_permission_cannot_reach_channels(): void
    {
        [$shop, $owner] = $this->setupShop();
        $staff = User::create(['shop_id' => $shop->id, 'name' => 'Cashier', 'phone' => '019'.random_int(10000000, 99999999), 'password' => 'password', 'role' => 'staff', 'permissions' => [], 'lang' => 'bn']);

        $this->actingAs($staff, 'web')->get(route('app.channels.index'))->assertStatus(403);
    }

    public function test_staff_with_the_permission_can_reach_channels(): void
    {
        [$shop, $owner] = $this->setupShop();
        $staff = User::create(['shop_id' => $shop->id, 'name' => 'Cashier', 'phone' => '019'.random_int(10000000, 99999999), 'password' => 'password', 'role' => 'staff', 'permissions' => ['online_business'], 'lang' => 'bn']);

        $this->actingAs($staff, 'web')->get(route('app.channels.index'))->assertOk();
    }

    public function test_order_prefix_must_be_unique_within_the_same_shop(): void
    {
        [$shop, $owner] = $this->setupShop();
        SalesChannel::create(['shop_id' => $shop->id, 'name' => 'Page A', 'platform_type' => 'facebook', 'order_prefix' => 'FB-']);

        $this->actingAs($owner, 'web')->post(route('app.channels.store'), [
            'name' => 'Page B', 'platform_type' => 'facebook', 'order_prefix' => 'FB-',
        ])->assertSessionHasErrors('order_prefix');
        $this->assertSame(1, SalesChannel::count());
    }

    public function test_the_same_order_prefix_is_allowed_on_a_different_shop(): void
    {
        [$shopA, $ownerA] = $this->setupShop();
        [$shopB, $ownerB] = $this->setupShop();
        SalesChannel::create(['shop_id' => $shopA->id, 'name' => 'Page A', 'platform_type' => 'facebook', 'order_prefix' => 'FB-']);

        $this->actingAs($ownerB, 'web')->post(route('app.channels.store'), [
            'name' => 'Page B', 'platform_type' => 'facebook', 'order_prefix' => 'FB-',
        ])->assertRedirect();

        // count() alone would resolve Tenancy::id() from whichever actingAs
        // call ran last (ownerB) and only ever see shop B's own row — this
        // bypasses that tenant scope specifically to prove BOTH shops' rows
        // really exist side by side under the identical prefix
        $this->assertSame(2, SalesChannel::withoutGlobalScopes()->count());
    }

    public function test_owner_can_update_and_delete_their_own_channel(): void
    {
        [$shop, $owner] = $this->setupShop();
        $channel = SalesChannel::create(['shop_id' => $shop->id, 'name' => 'Page A', 'platform_type' => 'facebook', 'order_prefix' => 'FB-']);

        $this->actingAs($owner, 'web')->put(route('app.channels.update', $channel->id), [
            'name' => 'Page A Renamed', 'platform_type' => 'website', 'order_prefix' => 'FB-', 'active' => false,
        ])->assertRedirect();
        $channel->refresh();
        $this->assertSame('Page A Renamed', $channel->name);
        $this->assertSame('website', $channel->platform_type);
        $this->assertFalse($channel->active);

        $this->actingAs($owner, 'web')->delete(route('app.channels.destroy', $channel->id))->assertRedirect();
        $this->assertSame(0, SalesChannel::count());
    }

    public function test_owner_cannot_touch_another_shops_channel(): void
    {
        [$shopA, $ownerA] = $this->setupShop();
        [$shopB, $ownerB] = $this->setupShop();
        $otherChannel = SalesChannel::create(['shop_id' => $shopB->id, 'name' => 'Foreign Channel', 'platform_type' => 'facebook', 'order_prefix' => 'FB-']);

        $this->actingAs($ownerA, 'web')->put(route('app.channels.update', $otherChannel->id), [
            'name' => 'Hijacked', 'platform_type' => 'facebook', 'order_prefix' => 'FB-',
        ])->assertNotFound();

        $this->actingAs($ownerA, 'web')->delete(route('app.channels.destroy', $otherChannel->id))->assertNotFound();
    }

    public function test_price_override_falls_back_to_the_products_normal_price(): void
    {
        [$shop, $owner] = $this->setupShop();
        $channel = SalesChannel::create(['shop_id' => $shop->id, 'name' => 'Page A', 'platform_type' => 'facebook', 'order_prefix' => 'FB-']);
        $product = Product::create(['shop_id' => $shop->id, 'name' => 'Rice', 'cost' => 10, 'price' => 20, 'stock' => 10]);

        // acting as the shop's own owner, same as every real call to this
        // method — ChannelProductPrice is tenant-scoped too, and resolving
        // Tenancy::id() needs an authenticated context to mean anything
        $this->actingAs($owner, 'web');
        $this->assertEquals(20.0, $channel->priceForProduct($product));
    }

    public function test_price_override_wins_over_the_products_normal_price(): void
    {
        [$shop, $owner] = $this->setupShop();
        $channel = SalesChannel::create(['shop_id' => $shop->id, 'name' => 'Page A', 'platform_type' => 'facebook', 'order_prefix' => 'FB-']);
        $product = Product::create(['shop_id' => $shop->id, 'name' => 'Rice', 'cost' => 10, 'price' => 20, 'stock' => 10]);
        ChannelProductPrice::create(['shop_id' => $shop->id, 'channel_id' => $channel->id, 'product_id' => $product->id, 'price' => 25]);

        $this->actingAs($owner, 'web');
        $this->assertEquals(25.0, $channel->priceForProduct($product));
    }

    public function test_owner_can_set_and_clear_a_channel_price_override(): void
    {
        [$shop, $owner] = $this->setupShop();
        $channel = SalesChannel::create(['shop_id' => $shop->id, 'name' => 'Page A', 'platform_type' => 'facebook', 'order_prefix' => 'FB-']);
        $product = Product::create(['shop_id' => $shop->id, 'name' => 'Rice', 'cost' => 10, 'price' => 20, 'stock' => 10]);

        $this->actingAs($owner, 'web')->patch(route('app.channels.setPrice', [$channel->id, $product->id]), ['price' => 18])->assertRedirect();
        $this->assertEquals(18.0, $channel->refresh()->priceForProduct($product));

        $this->actingAs($owner, 'web')->patch(route('app.channels.setPrice', [$channel->id, $product->id]), ['price' => null])->assertRedirect();
        $this->assertEquals(20.0, $channel->refresh()->priceForProduct($product));
    }

    public function test_channels_prices_page_shows_every_product_with_its_override(): void
    {
        [$shop, $owner] = $this->setupShop();
        $channel = SalesChannel::create(['shop_id' => $shop->id, 'name' => 'Page A', 'platform_type' => 'facebook', 'order_prefix' => 'FB-']);
        $product = Product::create(['shop_id' => $shop->id, 'name' => 'Rice', 'cost' => 10, 'price' => 20, 'discount_price' => 18, 'stock' => 10]);
        ChannelProductPrice::create(['shop_id' => $shop->id, 'channel_id' => $channel->id, 'product_id' => $product->id, 'price' => 15]);

        $response = $this->actingAs($owner, 'web')->get(route('app.channels.prices', $channel->id));

        // PHP's json_encode drops a float's trailing ".0" by default, so the
        // wire value here is the bare number, not strictly a float — assert
        // on that as Inertia's test helper actually decodes it
        $response->assertOk()->assertInertia(fn ($page) => $page
            ->where('products.0.default_price', 18)
            ->where('products.0.override_price', 15)
        );
    }
}
