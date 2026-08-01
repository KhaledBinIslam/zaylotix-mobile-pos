<?php

namespace Tests\Concerns;

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
