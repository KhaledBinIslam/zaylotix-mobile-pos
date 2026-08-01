<?php

namespace App\Support;

use App\Models\BusinessType;
use App\Models\Feature;
use App\Models\ProductCategory;
use App\Models\Shop;
use App\Models\Unit;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Creates a new shop + its owner user + seeds the shop's product categories
 * and units from config/business_types.php, based on the chosen business
 * type. Used by the admin "create shop" flow and by DemoShopSeeder.
 */
class ShopProvisioner
{
    /** @param  string[]  $featureKeys  keys from the `features` catalog to grant this shop immediately */
    public static function provision(array $shopAttrs, string $ownerName, ?string $ownerPhone, ?string $ownerEmail, string $ownerPassword, array $featureKeys = []): Shop
    {
        return DB::transaction(function () use ($shopAttrs, $ownerName, $ownerPhone, $ownerEmail, $ownerPassword, $featureKeys) {
            $shop = Shop::create($shopAttrs);

            if ($featureKeys) {
                $ids = Feature::whereIn('key', $featureKeys)->pluck('id');
                $shop->features()->sync($ids);
            }

            $businessType = BusinessType::find($shopAttrs['business_type_id'] ?? null);
            $defaults = $businessType ? (config('business_types.'.$businessType->slug) ?? []) : [];

            // must set the tenant explicitly — no shop user is authenticated yet
            Tenancy::set($shop->id);

            foreach (($defaults['categories'] ?? []) as $cat) {
                ProductCategory::create([
                    'shop_id' => $shop->id,
                    'name' => $cat['name'],
                    'name_en' => $cat['name_en'],
                    'emoji' => $cat['emoji'],
                ]);
            }

            foreach (($defaults['units'] ?? []) as $unit) {
                Unit::create([
                    'shop_id' => $shop->id,
                    'name' => $unit['name'],
                    'name_en' => $unit['name_en'],
                    'code' => $unit['code'],
                ]);
            }

            User::create([
                'shop_id' => $shop->id,
                'name' => $ownerName,
                'phone' => $ownerPhone,
                'email' => $ownerEmail,
                'password' => $ownerPassword,
                'role' => 'owner',
                'lang' => $shop->lang,
            ]);

            Tenancy::clear();

            return $shop;
        });
    }
}
