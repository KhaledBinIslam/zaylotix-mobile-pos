<?php

namespace Tests\Feature;

use App\Models\Admin;
use App\Models\SiteSetting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesShops;
use Tests\TestCase;

/**
 * How many days before a shop's subscription/trial expires the daily
 * zaylotix:payment-reminders command starts warning them — used to be a
 * hardcoded constant, now an admin-configurable value on SiteSetting.
 */
class ReminderSettingsTest extends TestCase
{
    use RefreshDatabase, CreatesShops;

    public function test_admin_can_update_the_reminder_day_count(): void
    {
        $admin = Admin::create(['name' => 'Admin', 'email' => 'reminder-admin@test.com', 'password' => 'password']);

        $response = $this->actingAs($admin, 'admin')->patch('/admin/site-settings/reminder-days', [
            'reminder_days' => 7,
        ]);

        $response->assertRedirect();
        $this->assertSame(7, SiteSetting::current()->fresh()->reminder_days);
    }

    public function test_reminder_days_out_of_range_is_rejected(): void
    {
        $admin = Admin::create(['name' => 'Admin', 'email' => 'reminder-admin2@test.com', 'password' => 'password']);

        $response = $this->actingAs($admin, 'admin')->patch('/admin/site-settings/reminder-days', [
            'reminder_days' => 0,
        ]);

        $response->assertSessionHasErrors('reminder_days');
    }

    public function test_a_shop_user_cannot_change_the_reminder_setting(): void
    {
        [, $owner] = $this->createShopWithOwner();

        $response = $this->actingAs($owner, 'web')->patch('/admin/site-settings/reminder-days', [
            'reminder_days' => 7,
        ]);

        $response->assertRedirect(route('admin.login'));
    }

    public function test_defaults_to_3_days_when_never_configured(): void
    {
        $this->assertSame(3, SiteSetting::current()->reminder_days);
    }

    public function test_the_reminder_command_only_reaches_shops_within_the_configured_window(): void
    {
        SiteSetting::current()->update(['reminder_days' => 7]);
        [$shop, $owner] = $this->createShopWithOwner([
            'subscription_expiry' => now()->addDays(5)->toDateString(),
        ]);

        $this->artisan('zaylotix:payment-reminders')->assertSuccessful();

        $this->assertSame(1, $owner->fresh()->notifications()->count());
    }

    public function test_the_reminder_command_skips_shops_outside_the_configured_window(): void
    {
        SiteSetting::current()->update(['reminder_days' => 3]);
        [$shop, $owner] = $this->createShopWithOwner([
            'subscription_expiry' => now()->addDays(5)->toDateString(),
        ]);

        $this->artisan('zaylotix:payment-reminders')->assertSuccessful();

        $this->assertSame(0, $owner->fresh()->notifications()->count());
    }
}
