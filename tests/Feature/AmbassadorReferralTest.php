<?php

namespace Tests\Feature;

use App\Models\Admin;
use App\Models\Ambassador;
use App\Models\AmbassadorCommission;
use App\Models\BusinessType;
use App\Models\Shop;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Phase 3 feature 10 — "অ্যাম্বাসেডর টুলস" (see docs/FIX_PLAN.md's design
 * note, scope confirmed with Khaled as just the referral code + signup
 * link piece). A shop that signs up through an ambassador's link gets
 * credited exactly as if an admin had manually tied the two together
 * via Admin\AmbassadorController::assignToShop() — the one-time bonus
 * AND the recurring assignment, both.
 */
class AmbassadorReferralTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): Admin
    {
        return Admin::create(['name' => 'Admin', 'email' => 'admin@test.com', 'password' => 'password']);
    }

    public function test_creating_an_ambassador_auto_generates_a_referral_code(): void
    {
        $shop = Shop::create(['name' => 'Target Shop', 'phone' => '01700000000']);

        $this->actingAs($this->admin(), 'admin')->post(route('admin.ambassadors.store'), [
            'name' => 'Rafiq', 'employee_id' => 'EMP-42', 'shop_id' => $shop->id,
        ]);

        $ambassador = Ambassador::where('employee_id', 'EMP-42')->first();
        $this->assertNotNull($ambassador);
        $this->assertNotNull($ambassador->referral_code);
    }

    public function test_signing_up_through_a_valid_referral_code_credits_the_ambassador(): void
    {
        $ambassador = Ambassador::create([
            'name' => 'Rafiq', 'employee_id' => 'EMP-42', 'referral_code' => 'EMP42',
            'one_time_bonus' => 500, 'recurring_percent' => 10, 'status' => 'active',
        ]);
        $type = BusinessType::create(['slug' => 'grocery', 'label_bn' => 'মুদির দোকান', 'label_en' => 'Grocery Shop', 'is_active' => true]);

        $this->post('/signup', [
            'name' => 'Karim Store', 'business_type_id' => $type->id, 'owner_name' => 'Karim',
            'phone' => '01711223344', 'password' => 'secret1', 'ref' => 'EMP42',
        ])->assertRedirect(route('app.pos'));

        $shop = Shop::where('phone', '01711223344')->first();
        $this->assertEquals($ambassador->id, $shop->brought_by_ambassador_id);
        $this->assertEquals($ambassador->id, $shop->currentAmbassador()?->id);

        $this->assertDatabaseHas('ambassador_commissions', [
            'ambassador_id' => $ambassador->id, 'shop_id' => $shop->id, 'type' => 'one_time', 'amount' => 500,
        ]);
    }

    public function test_signing_up_with_an_unknown_referral_code_still_succeeds(): void
    {
        $type = BusinessType::create(['slug' => 'grocery', 'label_bn' => 'মুদির দোকান', 'label_en' => 'Grocery Shop', 'is_active' => true]);

        $this->post('/signup', [
            'name' => 'Karim Store', 'business_type_id' => $type->id, 'owner_name' => 'Karim',
            'phone' => '01711223344', 'password' => 'secret1', 'ref' => 'NOSUCHCODE',
        ])->assertRedirect(route('app.pos'));

        $shop = Shop::where('phone', '01711223344')->first();
        $this->assertNotNull($shop);
        $this->assertNull($shop->brought_by_ambassador_id);
        $this->assertSame(0, AmbassadorCommission::count());
    }

    public function test_signing_up_with_no_ref_at_all_still_works_as_before(): void
    {
        $type = BusinessType::create(['slug' => 'grocery', 'label_bn' => 'মুদির দোকান', 'label_en' => 'Grocery Shop', 'is_active' => true]);

        $this->post('/signup', [
            'name' => 'Karim Store', 'business_type_id' => $type->id, 'owner_name' => 'Karim',
            'phone' => '01711223344', 'password' => 'secret1',
        ])->assertRedirect(route('app.pos'));

        $shop = Shop::where('phone', '01711223344')->first();
        $this->assertNotNull($shop);
        $this->assertNull($shop->brought_by_ambassador_id);
    }

    public function test_an_inactive_ambassadors_referral_code_does_not_credit_them(): void
    {
        $ambassador = Ambassador::create([
            'name' => 'Rafiq', 'employee_id' => 'EMP-42', 'referral_code' => 'EMP42', 'status' => 'inactive',
        ]);
        $type = BusinessType::create(['slug' => 'grocery', 'label_bn' => 'মুদির দোকান', 'label_en' => 'Grocery Shop', 'is_active' => true]);

        $this->post('/signup', [
            'name' => 'Karim Store', 'business_type_id' => $type->id, 'owner_name' => 'Karim',
            'phone' => '01711223344', 'password' => 'secret1', 'ref' => 'EMP42',
        ])->assertRedirect(route('app.pos'));

        $shop = Shop::where('phone', '01711223344')->first();
        $this->assertNull($shop->brought_by_ambassador_id);
    }

    public function test_signup_page_passes_through_the_ref_query_param(): void
    {
        $this->get('/signup?ref=EMP42')->assertInertia(fn ($page) => $page->where('ref', 'EMP42'));
    }
}
