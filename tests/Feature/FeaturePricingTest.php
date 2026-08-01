<?php

namespace Tests\Feature;

use App\Models\Admin;
use App\Models\BusinessType;
use App\Models\Feature;
use App\Models\Shop;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesShops;
use Tests\TestCase;

/**
 * Feature-wise pricing: each Feature can carry its own monthly_price, so the
 * admin can see (and set) a suggested subscription price built up from
 * exactly what a shop is granted — shops.monthly_fee is the actual number
 * charged, editable independently of the suggestion.
 */
class FeaturePricingTest extends TestCase
{
    use RefreshDatabase, CreatesShops;

    private function admin(): Admin
    {
        return Admin::create(['name' => 'Admin', 'email' => 'pricing-admin-'.uniqid().'@test.com', 'password' => 'password']);
    }

    public function test_admin_can_set_a_features_monthly_price(): void
    {
        $feature = Feature::create(['key' => 'test_feature', 'label_bn' => 'টেস্ট', 'label_en' => 'Test', 'category' => 'general']);

        $this->actingAs($this->admin(), 'admin')->put("/admin/features/{$feature->id}", [
            'label_bn' => 'টেস্ট', 'label_en' => 'Test', 'category' => 'general', 'is_active' => true,
            'monthly_price' => 150,
        ])->assertRedirect();

        $this->assertEquals(150.0, (float) $feature->fresh()->monthly_price);
    }

    public function test_creating_a_shop_stores_its_monthly_fee(): void
    {
        $businessType = BusinessType::first() ?? BusinessType::create(['slug' => 'general', 'label_bn' => 'সাধারণ', 'label_en' => 'General', 'is_active' => true]);

        $response = $this->actingAs($this->admin(), 'admin')->post('/admin/shops', [
            'name' => 'Test Shop', 'phone' => '01700000099',
            'business_type_id' => $businessType->id, 'sales_mode' => 'both',
            'plan' => 'trial', 'monthly_fee' => 990,
            'subscription_start' => now()->toDateString(), 'subscription_expiry' => now()->addDays(7)->toDateString(),
            'lang' => 'bn', 'owner_password' => '1234',
        ]);

        $response->assertRedirect(route('admin.shops.index'));
        $shop = Shop::where('phone', '01700000099')->first();
        $this->assertNotNull($shop);
        $this->assertEquals(990.0, (float) $shop->monthly_fee);
    }
}
