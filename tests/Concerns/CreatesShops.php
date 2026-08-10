<?php

namespace Tests\Concerns;

use App\Models\BusinessType;
use App\Models\Feature;
use App\Models\Shop;
use App\Models\User;

trait CreatesShops
{
    protected function grantFeature(Shop $shop, string $key): void
    {
        $feature = Feature::firstOrCreate(['key' => $key], [
            'label_bn' => $key, 'label_en' => $key, 'category' => 'general',
        ]);
        $shop->features()->syncWithoutDetaching([$feature->id]);

        // restaurant_tables carries special "which screen" routing semantics
        // (see Shop::isRestaurant()) that PosController/RestaurantTableController
        // decide on the shop's actual business type, not this flag — in real
        // usage it's only ever granted alongside business_type=restaurant, so
        // a test shop granted this feature needs to genuinely be one too, or
        // it'd exercise a combination ("restaurant feature, non-restaurant
        // shop") that can no longer happen outside a seeding bug.
        if ($key === 'restaurant_tables' && $shop->businessType?->slug !== 'restaurant') {
            $businessType = BusinessType::firstOrCreate(
                ['slug' => 'restaurant'],
                ['label_bn' => 'রেস্টুরেন্ট', 'label_en' => 'Restaurant', 'is_active' => true]
            );
            $shop->forceFill(['business_type_id' => $businessType->id])->save();
            $shop->unsetRelation('businessType');
        }
    }

    protected function createShopWithOwner(array $shopAttrs = [], array $userAttrs = []): array
    {
        $shop = Shop::create(array_merge([
            'name' => 'Test Shop '.uniqid(),
            'phone' => '017'.random_int(10000000, 99999999),
            'sales_mode' => 'both',
            'lang' => 'bn',
            'plan' => 'trial',
            'status' => 'active',
            'subscription_start' => now()->toDateString(),
            'subscription_expiry' => now()->addMonth()->toDateString(),
            'cash_balance' => 1000,
            'bank_balance' => 0,
            'capital' => 0,
            'vat_mode' => 'none',
            'invoice_counter' => 1000,
            // Most tests use this helper to stand up a shop that's already
            // "in normal use" and don't care about the first-login wizard —
            // only OnboardingWizardTest passes onboarded_at: null explicitly
            // to exercise that flow.
            'onboarded_at' => now(),
        ], $shopAttrs));

        $user = User::create(array_merge([
            'shop_id' => $shop->id,
            'name' => 'Owner',
            'phone' => $shop->phone,
            'password' => 'password',
            'role' => 'owner',
            'lang' => 'bn',
        ], $userAttrs));

        return [$shop, $user];
    }
}
