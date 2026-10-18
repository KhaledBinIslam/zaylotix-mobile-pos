<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesShops;
use Tests\TestCase;

/**
 * Phase 8 — see docs/FIX_PLAN.md. The mobile bottom nav's two flexible
 * slots (between the always-fixed Home/Sell/More) were hardcoded to
 * Stock + Due for every shop regardless of business type.
 */
class MobileNavSettingTest extends TestCase
{
    use RefreshDatabase, CreatesShops;

    public function test_defaults_to_stock_and_due_when_never_customized(): void
    {
        [$shop, $owner] = $this->createShopWithOwner();

        $response = $this->actingAs($owner, 'web')->get('/app/home');

        $response->assertInertia(fn ($page) => $page->where('shop.mobile_nav_items', ['stock', 'due']));
    }

    public function test_owner_can_customize_the_two_slots(): void
    {
        [$shop, $owner] = $this->createShopWithOwner();

        $this->actingAs($owner, 'web')->patch('/app/settings/mobile-nav', [
            'items' => ['reports', 'suppliers'],
        ])->assertRedirect();

        $this->assertSame(['reports', 'suppliers'], $shop->fresh()->mobile_nav_items);
    }

    public function test_an_empty_slot_is_dropped_not_saved_as_an_empty_string(): void
    {
        [$shop, $owner] = $this->createShopWithOwner();

        $this->actingAs($owner, 'web')->patch('/app/settings/mobile-nav', [
            'items' => ['reports', ''],
        ])->assertRedirect();

        $this->assertSame(['reports'], $shop->fresh()->mobile_nav_items);
    }

    public function test_an_unrecognized_key_is_rejected(): void
    {
        [$shop, $owner] = $this->createShopWithOwner();

        $response = $this->actingAs($owner, 'web')->patch('/app/settings/mobile-nav', [
            'items' => ['not-a-real-key'],
        ]);

        $response->assertSessionHasErrors('items.0');
        $this->assertNull($shop->fresh()->mobile_nav_items);
    }

    public function test_requires_the_settings_permission(): void
    {
        [$shop, $owner] = $this->createShopWithOwner();
        $staff = \App\Models\User::create([
            'shop_id' => $shop->id, 'name' => 'Cashier', 'phone' => '019'.random_int(10000000, 99999999),
            'password' => 'password', 'role' => 'staff', 'permissions' => ['pos'], 'lang' => 'bn',
        ]);

        $this->actingAs($staff, 'web')->patch('/app/settings/mobile-nav', ['items' => ['reports']])
            ->assertForbidden();
    }
}
