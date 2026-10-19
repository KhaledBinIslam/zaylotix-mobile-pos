<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesShops;
use Tests\TestCase;

/**
 * Phase 12 — see docs/FIX_PLAN.md. 'auto' (default) keeps every existing
 * shop's behavior unchanged; 'review' is an explicit owner opt-in that
 * pauses auto-syncing offline sales so they can be checked first.
 */
class OfflineSyncModeSettingTest extends TestCase
{
    use RefreshDatabase, CreatesShops;

    public function test_defaults_to_auto_for_every_existing_and_new_shop(): void
    {
        [$shop] = $this->createShopWithOwner();

        $this->assertSame('auto', $shop->fresh()->offline_sync_mode);
    }

    public function test_owner_can_switch_to_review_mode(): void
    {
        [$shop, $owner] = $this->createShopWithOwner();

        $this->actingAs($owner, 'web')->patch('/app/settings/offline-sync-mode', [
            'mode' => 'review',
        ])->assertRedirect();

        $this->assertSame('review', $shop->fresh()->offline_sync_mode);
    }

    public function test_owner_can_switch_back_to_auto(): void
    {
        [$shop, $owner] = $this->createShopWithOwner(['offline_sync_mode' => 'review']);

        $this->actingAs($owner, 'web')->patch('/app/settings/offline-sync-mode', [
            'mode' => 'auto',
        ])->assertRedirect();

        $this->assertSame('auto', $shop->fresh()->offline_sync_mode);
    }

    public function test_an_unrecognized_mode_is_rejected(): void
    {
        [$shop, $owner] = $this->createShopWithOwner();

        $this->actingAs($owner, 'web')->patch('/app/settings/offline-sync-mode', [
            'mode' => 'sometimes',
        ])->assertSessionHasErrors('mode');

        $this->assertSame('auto', $shop->fresh()->offline_sync_mode);
    }

    public function test_a_non_owner_staff_member_cannot_change_it_even_with_the_settings_permission(): void
    {
        [$shop] = $this->createShopWithOwner();
        $staff = \App\Models\User::create([
            'shop_id' => $shop->id, 'name' => 'Cashier', 'phone' => '019'.random_int(10000000, 99999999),
            'password' => 'password', 'role' => 'staff', 'permissions' => ['pos', 'settings'], 'lang' => 'bn',
        ]);

        $this->actingAs($staff, 'web')->patch('/app/settings/offline-sync-mode', ['mode' => 'review'])
            ->assertForbidden();

        $this->assertSame('auto', $shop->fresh()->offline_sync_mode);
    }
}
