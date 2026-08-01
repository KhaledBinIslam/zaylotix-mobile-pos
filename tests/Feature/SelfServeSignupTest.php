<?php

namespace Tests\Feature;

use App\Models\BusinessType;
use App\Models\Shop;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SelfServeSignupTest extends TestCase
{
    use RefreshDatabase;

    public function test_signup_page_lists_active_business_types(): void
    {
        BusinessType::create(['slug' => 'grocery', 'label_bn' => 'মুদির দোকান', 'label_en' => 'Grocery Shop', 'is_active' => true]);
        BusinessType::create(['slug' => 'hidden', 'label_bn' => 'হিডেন', 'label_en' => 'Hidden', 'is_active' => false]);

        $response = $this->get('/signup');

        $response->assertOk()->assertInertia(fn ($page) => $page
            ->has('businessTypes', 1)
            ->where('businessTypes.0.slug', 'grocery')
        );
    }

    public function test_signing_up_creates_a_trial_shop_and_logs_the_owner_in(): void
    {
        $type = BusinessType::create(['slug' => 'grocery', 'label_bn' => 'মুদির দোকান', 'label_en' => 'Grocery Shop', 'is_active' => true]);

        $response = $this->post('/signup', [
            'name' => 'Karim Store',
            'business_type_id' => $type->id,
            'owner_name' => 'Karim',
            'phone' => '01711223344',
            'password' => 'secret1',
            'area' => 'Dhaka',
        ]);

        $response->assertRedirect(route('app.home'));

        $shop = Shop::where('phone', '01711223344')->first();
        $this->assertNotNull($shop);
        $this->assertSame('Karim Store', $shop->name);
        $this->assertSame('trial', $shop->plan);
        $this->assertNull($shop->onboarded_at); // new shop, wizard should show

        $owner = User::where('phone', '01711223344')->first();
        $this->assertSame('owner', $owner->role);
        $this->assertAuthenticatedAs($owner, 'web');
    }

    public function test_signup_grants_the_recommended_feature_set_for_the_business_type(): void
    {
        $type = BusinessType::create(['slug' => 'grocery', 'label_bn' => 'মুদির দোকান', 'label_en' => 'Grocery Shop', 'is_active' => true]);
        $this->seed(\Database\Seeders\FeatureSeeder::class);

        $this->post('/signup', [
            'name' => 'Karim Store', 'business_type_id' => $type->id, 'owner_name' => 'Karim',
            'phone' => '01711223355', 'password' => 'secret1',
        ]);

        $shop = Shop::where('phone', '01711223355')->first();
        $this->assertContains('memo_print', $shop->featureKeys());
        $this->assertContains('memo_whatsapp', $shop->featureKeys());
    }

    public function test_signup_rejects_a_phone_already_used_by_another_shop(): void
    {
        $type = BusinessType::create(['slug' => 'grocery', 'label_bn' => 'মুদির দোকান', 'label_en' => 'Grocery Shop', 'is_active' => true]);
        Shop::create(['name' => 'Existing', 'phone' => '01799999999', 'business_type_id' => $type->id]);

        $response = $this->post('/signup', [
            'name' => 'New Shop', 'business_type_id' => $type->id, 'owner_name' => 'Someone',
            'phone' => '01799999999', 'password' => 'secret1',
        ]);

        $response->assertSessionHasErrors('phone');
        $this->assertSame(1, Shop::count());
    }

    public function test_signup_requires_a_valid_business_type(): void
    {
        $response = $this->post('/signup', [
            'name' => 'New Shop', 'business_type_id' => 999999, 'owner_name' => 'Someone',
            'phone' => '01788887777', 'password' => 'secret1',
        ]);

        $response->assertSessionHasErrors('business_type_id');
        $this->assertSame(0, Shop::count());
    }
}
