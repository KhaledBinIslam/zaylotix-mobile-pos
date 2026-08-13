<?php

namespace Tests\Feature;

use App\Models\Admin;
use App\Models\BusinessType;
use App\Models\SiteSetting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesShops;
use Tests\TestCase;

/**
 * Covers the public landing page (replacing root's old redirect straight to
 * the login form) and making the shop-footer owner/contact info admin-set
 * per shop instead of hardcoded to Khaled's own name/number everywhere.
 */
class LandingPageAndSupportContactTest extends TestCase
{
    use RefreshDatabase, CreatesShops;

    private function superAdmin(): Admin
    {
        return Admin::create(['name' => 'Super', 'email' => 'landing-'.uniqid().'@test.com', 'password' => 'password', 'role' => 'super_admin']);
    }

    public function test_landing_page_has_no_whatsapp_link_when_admin_has_not_set_one(): void
    {
        $response = $this->get('/');

        $response->assertOk()->assertInertia(fn ($page) => $page
            ->component('App/Landing/Index')
            ->where('whatsappContact', null)
        );
    }

    public function test_landing_page_passes_through_the_admin_set_whatsapp_number(): void
    {
        SiteSetting::current()->update(['whatsapp_contact' => '8801979894356']);

        $response = $this->get('/');

        $response->assertOk()->assertInertia(fn ($page) => $page
            ->where('whatsappContact', '8801979894356')
        );
    }

    /** Regression: login/signup themselves must still render fine — only root's redirect target changed. */
    public function test_login_and_signup_pages_still_render_normally(): void
    {
        $this->get('/login')->assertOk();
        $this->get('/signup')->assertOk();
    }

    public function test_admin_can_set_a_shops_support_contact_and_it_is_shared_to_that_shops_frontend(): void
    {
        $type = BusinessType::firstOrCreate(['slug' => 'general'], ['label_bn' => 'সাধারণ', 'label_en' => 'General', 'is_active' => true]);
        [$shop, $owner] = $this->createShopWithOwner(['business_type_id' => $type->id]);
        $admin = $this->superAdmin();

        $response = $this->actingAs($admin, 'admin')->put("/admin/shops/{$shop->id}", [
            'name' => $shop->name, 'phone' => $shop->phone, 'business_type_id' => $shop->business_type_id,
            'sales_mode' => $shop->sales_mode, 'plan' => $shop->plan, 'lang' => $shop->lang,
            'status' => $shop->status,
            'subscription_start' => $shop->subscription_start->toDateString(),
            'subscription_expiry' => $shop->subscription_expiry->toDateString(),
            'support_contact_name' => 'Zaylotix Support',
            'support_contact_phone' => '01700000000',
        ]);
        $response->assertSessionDoesntHaveErrors()->assertRedirect();

        $shop->refresh();
        $this->assertSame('Zaylotix Support', $shop->support_contact_name);
        $this->assertSame('01700000000', $shop->support_contact_phone);

        $page = $this->actingAs($owner, 'web')->get('/app/home');
        $page->assertInertia(fn ($assert) => $assert
            ->where('shop.support_contact_name', 'Zaylotix Support')
            ->where('shop.support_contact_phone', '01700000000')
        );
    }

    /** A shop the admin never set this for shows nothing — no stale default, no other shop's info leaking in. */
    public function test_a_shop_with_no_support_contact_set_shares_null(): void
    {
        [, $owner] = $this->createShopWithOwner();

        $page = $this->actingAs($owner, 'web')->get('/app/home');

        $page->assertInertia(fn ($assert) => $assert
            ->where('shop.support_contact_name', null)
            ->where('shop.support_contact_phone', null)
        );
    }

    public function test_admin_can_set_the_platform_whatsapp_contact(): void
    {
        $admin = $this->superAdmin();

        $response = $this->actingAs($admin, 'admin')->patch('/admin/site-settings/whatsapp-contact', [
            'whatsapp_contact' => '8801979894356',
        ]);
        $response->assertSessionDoesntHaveErrors()->assertRedirect();

        $this->assertSame('8801979894356', SiteSetting::current()->whatsapp_contact);
    }
}
