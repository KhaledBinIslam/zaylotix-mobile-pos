<?php

namespace App\Console\Commands;

use App\Models\ProductCategory;
use App\Models\Shop;
use App\Models\Unit;
use App\Support\ShopCloner;
use App\Support\ShopProvisioner;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

/**
 * Liberty Bali: "amr Liberty Bali name e notun ekta shop lage, baby park
 * er motoi" — a brand-new, fully INDEPENDENT paid shop (own owner login,
 * no shared stock/account with Baby Park), but starting from an identical
 * article/barcode/category setup, every stock figure at 0 ("pore color
 * size dekhe stock tule nibe").
 *
 * Creates the shop (ShopProvisioner — same path the admin "create shop"
 * screen uses), wipes its generic business-type seed categories/units,
 * then clones Baby Park's actual catalog onto it (ShopCloner — NOT
 * CatalogSync::syncToBranch(), which would copy live stock numbers when
 * the source has shared_stock_pool on).
 */
class ShopsCloneFromTemplate extends Command
{
    protected $signature = 'shops:clone-from-template
        {--source= : source shop id or name}
        {--name= : new shop\'s name}
        {--name-en= : new shop\'s English name, defaults to --name}
        {--phone= : new shop\'s contact number, also the owner\'s login phone}
        {--owner-name= : defaults to --name}
        {--owner-password=}
        {--dry-run}';

    protected $description = 'Creates a brand-new, independent shop and clones a source shop\'s full catalog onto it, every stock figure at 0.';

    public function handle(): int
    {
        $source = $this->resolveSource();
        if (! $source) {
            $this->error('উৎস শপ পাওয়া যায়নি (--source=)।');

            return self::FAILURE;
        }

        $name = trim((string) $this->option('name'));
        $phone = trim((string) $this->option('phone'));
        $ownerPassword = (string) $this->option('owner-password');

        if ($name === '' || $phone === '' || $ownerPassword === '') {
            $this->error('--name, --phone, --owner-password তিনটাই দিতে হবে।');

            return self::FAILURE;
        }
        if (strlen($ownerPassword) < 4) {
            $this->error('--owner-password অন্তত ৪ অক্ষর হতে হবে।');

            return self::FAILURE;
        }
        if (Shop::withoutGlobalScopes()->where('phone', $phone)->exists()) {
            $this->error("এই ফোন নম্বর ({$phone}) দিয়ে আগেই একটা শপ আছে।");

            return self::FAILURE;
        }

        $nameEn = trim((string) $this->option('name-en')) ?: $name;
        $ownerName = trim((string) $this->option('owner-name')) ?: $name;

        $sourceCategoryCount = ProductCategory::withoutGlobalScopes()->where('shop_id', $source->id)->count();
        $sourceUnitCount = Unit::withoutGlobalScopes()->where('shop_id', $source->id)->count();
        $featureKeys = ShopCloner::featureKeysFrom($source);

        $this->line("উৎস: শপ {$source->id} — {$source->name} ({$sourceCategoryCount}টা ক্যাটাগরি, {$sourceUnitCount}টা ইউনিট)");
        $this->line('ফিচার কপি হবে: '.implode(', ', $featureKeys));
        $this->line("নতুন শপ: \"{$name}\" / ফোন {$phone} / owner পাসওয়ার্ড সেট করা থাকবে");

        if ($this->option('dry-run')) {
            $this->info('dry-run — কিছু লেখা হয়নি।');

            return self::SUCCESS;
        }

        [$start, $expiry] = $this->subscriptionWindow($source->plan);

        $shopAttrs = array_merge(ShopCloner::settingsFrom($source), [
            'business_type_id' => $source->business_type_id,
            'name' => $name, 'name_en' => $nameEn,
            'phone' => $phone,
            'plan' => $source->plan, 'account_type' => $source->account_type,
            'monthly_fee' => $source->monthly_fee, 'staff_limit' => $source->staff_limit,
            'status' => 'active',
            'subscription_start' => $start, 'subscription_expiry' => $expiry,
        ]);

        $newShop = ShopProvisioner::provision(
            shopAttrs: $shopAttrs,
            ownerName: $ownerName,
            ownerPhone: $phone,
            ownerEmail: null,
            ownerPassword: $ownerPassword,
            featureKeys: $featureKeys,
        );

        // the generic business-type seed ShopProvisioner just created would
        // otherwise sit alongside Baby Park's real, cloned categories —
        // this shop's whole catalog is about to be replaced wholesale
        ProductCategory::withoutGlobalScopes()->where('shop_id', $newShop->id)->delete();
        Unit::withoutGlobalScopes()->where('shop_id', $newShop->id)->delete();

        $counts = ShopCloner::cloneCatalog($source, $newShop);
        ShopCloner::bumpAllCounters($newShop);

        $this->info("শপ তৈরি হলো — id {$newShop->id}");
        $this->line("  ক্যাটাগরি: {$counts['categories']}, ইউনিট: {$counts['units']}, প্রোডাক্ট: {$counts['products']}, ভ্যারিয়েন্ট: {$counts['variants']} (সব স্টক ০)");
        $this->line("  লগইন: ফোন {$phone}, পাসওয়ার্ড যা দেওয়া হয়েছে");

        return self::SUCCESS;
    }

    private function resolveSource(): ?Shop
    {
        $option = trim((string) $this->option('source'));
        if ($option === '') {
            return null;
        }
        if (ctype_digit($option)) {
            return Shop::withoutGlobalScopes()->find((int) $option);
        }

        return Shop::withoutGlobalScopes()->where('name', 'like', '%'.$option.'%')->first();
    }

    /** @return array{0:string,1:string} */
    private function subscriptionWindow(string $plan): array
    {
        $start = Carbon::today();
        $expiry = match ($plan) {
            'yearly' => $start->copy()->addYear(),
            'trial' => $start->copy()->addDays(14),
            default => $start->copy()->addMonth(),
        };

        return [$start->toDateString(), $expiry->toDateString()];
    }
}
