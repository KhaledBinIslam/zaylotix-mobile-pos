<?php

namespace App\Console\Commands;

use App\Models\BusinessType;
use App\Models\Feature;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\Sale;
use App\Models\Shop;
use App\Models\Unit;
use App\Models\User;
use App\Support\CatalogSync;
use App\Support\ShopProvisioner;
use App\Support\Tenancy;
use Database\Seeders\FeatureSeeder;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * One-shot, idempotent setup for Baby Park — a shoe shop, 2 branches
 * sharing one stock pool, Khaled's first paying client. See
 * docs/BABY_PARK_PLAN.md for the full audit/decision record.
 *
 * Safe to re-run: looks up the main shop by its owner phone and the branch
 * by (parent_shop_id, name) before creating anything, and a re-run only
 * re-syncs features/categories/units rather than duplicating rows.
 */
class SetupBabyPark extends Command
{
    protected $signature = 'shop:setup-baby-park {--dry-run} {--password=} {--rollback}';

    protected $description = 'Idempotent setup for Baby Park (shoe shop, 2 branches, shared stock pool). --dry-run previews only. --rollback removes it (refuses if any sale exists).';

    private const OWNER_PHONE = '01864987754';

    private const SHOP_NAME = 'Baby Park';

    /** Grant to the MAIN shop only — the three flags below are main-shop-only by established convention (see docs/BABY_PARK_PLAN.md). */
    private const MAIN_ONLY_FEATURES = ['shared_catalog', 'shared_stock_pool'];

    private const FEATURE_KEYS = [
        'memo_whatsapp', 'memo_print', 'reports', 'expenses', 'accounts', 'quotations',
        'cashier_management', 'export', 'barcode_printing', 'purchases', 'returns', 'damages',
        'stock_count', 'product_variants', 'whatsapp_bulk', 'activity_log', 'promotions',
        'loyalty_points', 'hr_payroll', 'suppliers', 'low_stock_alerts', 'wholesale_pricing',
        'installment_sales', 'shop_backup', 'online_business',
        'shared_catalog', 'shared_stock_pool',
    ];

    private const DEFAULT_SIZES = '28,29,30,31,32,33,34,35,36,37,38,39,40,41,42,43,44';

    private const DEFAULT_COLORS = 'কালো, সাদা, বাদামী, নেভি ব্লু, লাল';

    public function handle(): int
    {
        if ($this->option('rollback')) {
            return $this->rollback();
        }

        $isDryRun = (bool) $this->option('dry-run');
        $existingShop = Shop::withoutGlobalScopes()->where('phone', self::OWNER_PHONE)->first();
        $existingBranch = $existingShop
            ? Shop::withoutGlobalScopes()->where('parent_shop_id', $existingShop->id)->where('name', self::SHOP_NAME)->first()
            : null;

        $this->info('--- Baby Park সেটআপ ---');
        $this->line('Main shop: '.($existingShop ? "আগেই আছে (ID {$existingShop->id})" : 'নতুন তৈরি হবে'));
        $this->line('Branch: '.($existingBranch ? "আগেই আছে (ID {$existingBranch->id})" : 'নতুন তৈরি হবে'));
        $this->line('Main শপের ফিচার: '.implode(', ', self::FEATURE_KEYS));
        $this->line('Branch-এর ফিচার: '.implode(', ', array_values(array_diff(self::FEATURE_KEYS, self::MAIN_ONLY_FEATURES))));

        if ($isDryRun) {
            $this->newLine();
            $this->info('এটা শুধু প্রিভিউ — কিছু লেখা হয়নি। ঠিক থাকলে --dry-run ছাড়া আবার চালান।');

            return self::SUCCESS;
        }

        $password = $this->option('password') ?: Str::password(12);
        $passwordWasGenerated = ! $this->option('password');

        [$mainShop, $branch] = DB::transaction(function () use ($existingShop, $existingBranch, $password) {
            // Defensive — guarantees every Feature row this command grants
            // actually exists, regardless of whether FeatureSeeder has been
            // re-run on this environment since shared_stock_pool/shared_catalog
            // etc. were added. Idempotent (updateOrCreate per key), same as
            // the BusinessType::updateOrCreate() call right below.
            (new FeatureSeeder)->run();

            BusinessType::updateOrCreate(
                ['slug' => 'footwear'],
                array_merge(config('business_types.footwear'), ['is_active' => true])
            );

            if ($existingShop) {
                $existingShop->features()->sync(Feature::whereIn('key', self::FEATURE_KEYS)->pluck('id'));
                $existingShop->update([
                    'default_variant_sizes' => $existingShop->default_variant_sizes ?? self::DEFAULT_SIZES,
                    'default_variant_colors' => $existingShop->default_variant_colors ?? self::DEFAULT_COLORS,
                ]);
                $mainShop = $existingShop;
            } else {
                $businessType = BusinessType::where('slug', 'footwear')->first();
                $mainShop = ShopProvisioner::provision(
                    [
                        'name' => self::SHOP_NAME,
                        'phone' => self::OWNER_PHONE,
                        'business_type_id' => $businessType->id,
                        'sales_mode' => 'both',
                        'lang' => 'bn',
                        'plan' => 'monthly',
                        'account_type' => 'paid',
                        'status' => 'active',
                        'subscription_start' => now()->toDateString(),
                        'subscription_expiry' => now()->addDays(30)->toDateString(),
                        'cash_balance' => 0,
                        'bank_balance' => 0,
                        'capital' => 0,
                        'vat_mode' => 'none',
                        'invoice_counter' => 1000,
                        'default_variant_sizes' => self::DEFAULT_SIZES,
                        'default_variant_colors' => self::DEFAULT_COLORS,
                        'onboarded_at' => now(),
                    ],
                    'Baby Park Owner',
                    self::OWNER_PHONE,
                    null,
                    $password,
                    self::FEATURE_KEYS
                );
            }

            $branchFeatureKeys = array_values(array_diff(self::FEATURE_KEYS, self::MAIN_ONLY_FEATURES));

            if ($existingBranch) {
                $existingBranch->features()->sync(Feature::whereIn('key', $branchFeatureKeys)->pluck('id'));
                $branch = $existingBranch;
            } else {
                // Mirrors Admin\ShopController::createBranch()'s established
                // pattern exactly — a branch never gets its own owner User;
                // the one owner switches via BranchController.
                $branch = Shop::create([
                    'name' => self::SHOP_NAME,
                    // shops.phone is NOT NULL/unique at the DB level (unlike
                    // the branch owner's own User.phone, which legitimately
                    // has none yet) — a clearly-fake placeholder Khaled
                    // replaces once he has the branch's real number (Admin >
                    // Shops > Edit), same intent as the ticket's "আমি পরে
                    // ঠিক করব".
                    'phone' => 'PENDING-'.Str::random(8),
                    'area' => 'শাখা ২',
                    'parent_shop_id' => $mainShop->id,
                    'business_type_id' => $mainShop->business_type_id,
                    'name_en' => $mainShop->name_en,
                    'owner_name' => $mainShop->owner_name,
                    'sales_mode' => $mainShop->sales_mode,
                    'lang' => $mainShop->lang,
                    'plan' => $mainShop->plan,
                    'account_type' => $mainShop->account_type,
                    'status' => 'active',
                    'subscription_start' => $mainShop->subscription_start,
                    'subscription_expiry' => $mainShop->subscription_expiry,
                    'vat_mode' => $mainShop->vat_mode,
                    'invoice_counter' => 1000,
                    'onboarded_at' => now(),
                ]);
                $branch->features()->sync(Feature::whereIn('key', $branchFeatureKeys)->pluck('id'));
                CatalogSync::syncToBranch($mainShop, $branch);
            }

            return [$mainShop, $branch];
        });

        $this->newLine();
        $this->info('✅ Baby Park সেটআপ সম্পন্ন।');
        $this->line("Main shop ID: {$mainShop->id} (ফোন: ".self::OWNER_PHONE.')');
        $this->line("Branch ID: {$branch->id} (ফোন আপাতত placeholder — পরে Admin > Shops > Edit থেকে আসল নম্বর দিন)");
        if ($passwordWasGenerated) {
            $this->warn("পাসওয়ার্ড (এখনই সেভ করুন, আর দেখানো হবে না): {$password}");
        } else {
            $this->info('পাসওয়ার্ড দেওয়া অপশন থেকে সেট হয়েছে।');
        }

        return self::SUCCESS;
    }

    private function rollback(): int
    {
        $mainShop = Shop::withoutGlobalScopes()->where('phone', self::OWNER_PHONE)->first();
        if (! $mainShop) {
            $this->info('কোনো Baby Park শপ পাওয়া যায়নি — করার কিছু নেই।');

            return self::SUCCESS;
        }

        $branch = Shop::withoutGlobalScopes()->where('parent_shop_id', $mainShop->id)->where('name', self::SHOP_NAME)->first();
        $shopIds = array_filter([$mainShop->id, $branch?->id]);

        $saleCount = Sale::withoutGlobalScopes()->whereIn('shop_id', $shopIds)->count();
        if ($saleCount > 0) {
            $this->error("রোলব্যাক বন্ধ করা হলো — {$saleCount}টা বিক্রি হয়ে গেছে। এখন আর নিরাপদে ফিরিয়ে নেওয়া যাবে না।");

            return self::FAILURE;
        }

        DB::transaction(function () use ($shopIds) {
            Product::withoutGlobalScopes()->whereIn('shop_id', $shopIds)->forceDelete();
            ProductCategory::withoutGlobalScopes()->whereIn('shop_id', $shopIds)->delete();
            Unit::withoutGlobalScopes()->whereIn('shop_id', $shopIds)->delete();
            User::withoutGlobalScopes()->whereIn('shop_id', $shopIds)->delete();
            Shop::withoutGlobalScopes()->whereIn('id', $shopIds)->delete();
        });

        $this->info('Baby Park শপ, ব্রাঞ্চ, এবং সব প্রোডাক্ট/ক্যাটাগরি/ইউনিট/ইউজার ডিলিট করা হয়েছে।');

        return self::SUCCESS;
    }
}
