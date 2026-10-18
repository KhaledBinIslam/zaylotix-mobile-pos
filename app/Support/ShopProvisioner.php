<?php

namespace App\Support;

use App\Models\BusinessType;
use App\Models\Feature;
use App\Models\ProductCategory;
use App\Models\Shop;
use App\Models\Unit;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

/**
 * Creates a new shop + its owner user + seeds the shop's product categories
 * and units from config/business_types.php, based on the chosen business
 * type. Used by the admin "create shop" flow, self-serve signup, and by
 * DemoShopSeeder.
 *
 * Tenancy contract: provision() explicitly sets the tenant to the new shop
 * while it seeds categories/units (needed since no shop user is
 * authenticated yet), then clears it again before returning — so by design
 * a caller gets no lingering tenant context after a normal provision()
 * call. This is deliberate: the one container-bound override Tenancy::id()
 * honors (see Tenancy::set()) takes priority over the real logged-in user's
 * own shop, so leaving it bound past this call would leak into any
 * tenant-scoped code that runs later in the same request (e.g. an admin
 * guard request would start resolving a tenant it never should). A caller
 * that genuinely needs to keep doing tenant-scoped work immediately after
 * provisioning (e.g. seeding demo products) should call
 * provisionKeepingTenancy() instead and is then responsible for calling
 * Tenancy::clear() itself when done.
 */
class ShopProvisioner
{
    /** @param  string[]  $featureKeys  keys from the `features` catalog to grant this shop immediately */
    public static function provision(array $shopAttrs, string $ownerName, ?string $ownerPhone, ?string $ownerEmail, string $ownerPassword, array $featureKeys = []): Shop
    {
        $shop = static::provisionKeepingTenancy($shopAttrs, $ownerName, $ownerPhone, $ownerEmail, $ownerPassword, $featureKeys);
        Tenancy::clear();

        return $shop;
    }

    /**
     * Same as provision(), but leaves Tenancy pointed at the new shop when
     * it returns instead of clearing it — for a caller that needs to keep
     * doing tenant-scoped work (seeding demo data, etc.) right after. The
     * caller must call Tenancy::clear() itself once it's done.
     *
     * @param  string[]  $featureKeys
     */
    public static function provisionKeepingTenancy(array $shopAttrs, string $ownerName, ?string $ownerPhone, ?string $ownerEmail, string $ownerPassword, array $featureKeys = []): Shop
    {
        return DB::transaction(function () use ($shopAttrs, $ownerName, $ownerPhone, $ownerEmail, $ownerPassword, $featureKeys) {
            // Re-check the unique phone under a row lock inside the
            // transaction — Laravel's `unique:shops,phone` request validation
            // already blocks the common case, but two near-simultaneous
            // submits can both pass validation before either INSERT commits.
            // Without this guard that race surfaces as an uncaught
            // QueryException (raw 500) instead of a friendly error.
            if (! empty($shopAttrs['phone'])) {
                $existing = Shop::withoutGlobalScopes()->where('phone', $shopAttrs['phone'])->lockForUpdate()->first();
                if ($existing) {
                    throw ValidationException::withMessages([
                        'phone' => 'এই ফোন নম্বর দিয়ে আগেই একটি অ্যাকাউন্ট আছে।',
                    ]);
                }
            }

            $shop = Shop::create($shopAttrs);

            if ($featureKeys) {
                $ids = Feature::whereIn('key', $featureKeys)->pluck('id');
                $shop->features()->sync($ids);
            }

            $businessType = BusinessType::find($shopAttrs['business_type_id'] ?? null);
            $defaults = $businessType ? config('business_types.'.$businessType->slug) : null;

            // A BusinessType row can exist with no matching config entry —
            // it's a DB row, freely creatable from Admin > Business Types,
            // while config/business_types.php is a static file the two are
            // never automatically kept in sync. Rather than silently leaving
            // a shop with zero categories/units (every "new shop" assumption
            // elsewhere breaks quietly), fall back to the 'general' profile
            // so a shop never ends up with literally nothing to work with.
            if ($defaults === null) {
                if ($businessType) {
                    Log::warning("ShopProvisioner: business type '{$businessType->slug}' has no matching config/business_types.php entry — falling back to 'general' defaults.");
                }
                $defaults = config('business_types.general') ?? [];
            }

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

            return $shop;
        });
    }
}
