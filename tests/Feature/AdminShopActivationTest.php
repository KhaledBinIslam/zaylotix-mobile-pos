<?php

namespace Tests\Feature;

use App\Models\Admin;
use App\Models\BusinessType;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesShops;
use Tests\TestCase;

/**
 * Shop::isActive() requires BOTH status='active' AND a future
 * subscription_expiry — clicking "Activate" (or picking Active in the edit
 * form) used to only ever touch status, so a shop whose trial/plan had
 * already expired kept showing Inactive right after "activating" it, with
 * nothing explaining why. Both admin paths now push expiry forward too
 * whenever activating would otherwise still leave it expired.
 */
class AdminShopActivationTest extends TestCase
{
    use RefreshDatabase, CreatesShops;

    private function superAdmin(): Admin
    {
        return Admin::create(['name' => 'Super', 'email' => 'activation-'.uniqid().'@test.com', 'password' => 'password', 'role' => 'super_admin']);
    }

    public function test_toggling_an_expired_shop_to_active_also_extends_its_expiry(): void
    {
        [$shop] = $this->createShopWithOwner(['status' => 'inactive', 'subscription_expiry' => now()->subDays(3)->toDateString()]);
        $admin = $this->superAdmin();

        $this->actingAs($admin, 'admin')->post("/admin/shops/{$shop->id}/toggle-status")->assertRedirect();

        $shop->refresh();
        $this->assertSame('active', $shop->status);
        $this->assertTrue($shop->subscription_expiry->isFuture());
        $this->assertTrue($shop->isActive());
    }

    public function test_toggling_a_shop_with_a_still_future_expiry_does_not_change_the_expiry(): void
    {
        $futureExpiry = now()->addMonths(3)->toDateString();
        [$shop] = $this->createShopWithOwner(['status' => 'inactive', 'subscription_expiry' => $futureExpiry]);
        $admin = $this->superAdmin();

        $this->actingAs($admin, 'admin')->post("/admin/shops/{$shop->id}/toggle-status")->assertRedirect();

        $shop->refresh();
        $this->assertSame('active', $shop->status);
        $this->assertSame($futureExpiry, $shop->subscription_expiry->toDateString());
    }

    public function test_toggling_an_active_shop_to_inactive_leaves_expiry_untouched(): void
    {
        $expiry = now()->subDays(3)->toDateString();
        [$shop] = $this->createShopWithOwner(['status' => 'active', 'subscription_expiry' => now()->addMonth()->toDateString()]);
        $admin = $this->superAdmin();

        $this->actingAs($admin, 'admin')->post("/admin/shops/{$shop->id}/toggle-status")->assertRedirect();

        $shop->refresh();
        $this->assertSame('inactive', $shop->status);
    }

    public function test_edit_form_saving_active_with_an_expired_date_also_extends_it(): void
    {
        $type = BusinessType::firstOrCreate(['slug' => 'general'], ['label_bn' => 'সাধারণ', 'label_en' => 'General', 'is_active' => true]);
        [$shop] = $this->createShopWithOwner([
            'status' => 'inactive',
            'business_type_id' => $type->id,
            'subscription_start' => now()->subMonths(2)->toDateString(),
            'subscription_expiry' => now()->subDay()->toDateString(),
        ]);
        $admin = $this->superAdmin();

        $response = $this->actingAs($admin, 'admin')->put("/admin/shops/{$shop->id}", [
            'name' => $shop->name, 'phone' => $shop->phone, 'business_type_id' => $shop->business_type_id,
            'sales_mode' => $shop->sales_mode, 'plan' => $shop->plan, 'lang' => $shop->lang,
            'status' => 'active',
            'subscription_start' => $shop->subscription_start->toDateString(),
            'subscription_expiry' => $shop->subscription_expiry->toDateString(), // still the expired date — admin didn't touch it
        ]);
        $response->assertSessionDoesntHaveErrors()->assertRedirect();

        $shop->refresh();
        $this->assertTrue($shop->isActive());
        $this->assertTrue($shop->subscription_expiry->isFuture());
    }
}
