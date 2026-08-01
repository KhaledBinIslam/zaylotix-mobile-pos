<?php

namespace Tests\Feature;

use App\Models\Admin;
use App\Support\FeatureRecommendations;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesShops;
use Tests\TestCase;

/**
 * Business-type-aware feature defaults: a small grocery shop shouldn't have
 * to be manually configured with the same 15+ feature checklist as a
 * supershop or pharmacy — config/business_types.php's `features` list
 * pre-checks a sensible starting set on Create Shop, and the same data
 * surfaces as a "recommended for" note on the admin Features page.
 */
class FeatureRecommendationTest extends TestCase
{
    use RefreshDatabase, CreatesShops;

    public function test_grocery_recommends_a_smaller_set_than_supershop(): void
    {
        $grocery = FeatureRecommendations::forBusinessType('grocery');
        $supershop = FeatureRecommendations::forBusinessType('supershop');

        $this->assertNotEmpty($grocery);
        $this->assertLessThan(count($supershop), count($grocery));
        $this->assertNotContains('activity_log', $grocery); // not needed for a one-person shop
        $this->assertContains('activity_log', $supershop);
    }

    public function test_every_business_type_with_memo_print_also_gets_memo_whatsapp(): void
    {
        // A shop that can print a memo must also be able to send it over
        // WhatsApp — this was previously true only for 'supershop', leaving
        // every other business type without the WhatsApp bill option.
        foreach (array_keys(config('business_types')) as $slug) {
            $features = config("business_types.{$slug}.features");
            if (in_array('memo_print', $features, true)) {
                $this->assertContains('memo_whatsapp', $features, "{$slug} has memo_print but not memo_whatsapp");
            }
        }
    }

    public function test_unknown_business_type_returns_no_recommendations(): void
    {
        $this->assertSame([], FeatureRecommendations::forBusinessType('not-a-real-type'));
        $this->assertSame([], FeatureRecommendations::forBusinessType(null));
    }

    public function test_admin_features_page_shows_recommended_for_business_types(): void
    {
        $admin = Admin::create(['name' => 'Admin', 'email' => 'rec-admin@test.com', 'password' => 'password']);

        $response = $this->actingAs($admin, 'admin')->get('/admin/features');

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->has('recommendedFor.accounts') // every business type recommends 'accounts'
        );
    }

    public function test_admin_create_shop_page_passes_default_features_per_business_type(): void
    {
        $admin = Admin::create(['name' => 'Admin', 'email' => 'rec-admin2@test.com', 'password' => 'password']);
        \App\Models\BusinessType::create(['slug' => 'grocery', 'label_bn' => 'মুদির দোকান', 'label_en' => 'Grocery Shop', 'is_active' => true]);

        $response = $this->actingAs($admin, 'admin')->get('/admin/shops/create');

        $response->assertOk();
        $businessTypes = $response->getOriginalContent()->getData()['page']['props']['businessTypes'];
        $this->assertNotEmpty($businessTypes[0]['default_features']);
    }
}
