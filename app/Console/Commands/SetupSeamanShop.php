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
use App\Support\ShopProvisioner;
use Database\Seeders\FeatureSeeder;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * One-shot, idempotent setup for SEAMAN SHOE STORE — merchant-navy /
 * industrial safety gear and uniforms, Khaled's SECOND paying client.
 * Standalone shop, no branch. Mirrors SetupBabyPark's established pattern
 * (the first paid onboarding) rather than inventing a new one.
 *
 * Paid from day one, exactly the way Baby Park is set up: plan=monthly,
 * account_type=paid, status=active, subscription extended by the admin's
 * subscription-payment flow — no trial fields, no demo data, no demo
 * limits (none of those are tied to account_type=paid anywhere; verified
 * by reading ExpireSubscriptions + Shop::scopePaid before writing this).
 *
 * Safe to re-run: looks the shop up by the owner's phone first; a re-run
 * only re-syncs features and fills missing fields, never duplicates.
 */
class SetupSeamanShop extends Command
{
    protected $signature = 'shop:setup-seaman {--dry-run} {--password=} {--rollback}';

    protected $description = 'Idempotent setup for SEAMAN SHOE STORE (standalone, paid). --dry-run previews only. --rollback removes it (refuses if any sale exists).';

    private const OWNER_PHONE = '01833722077';

    private const SHOP_NAME = 'SEAMAN SHOE STORE';

    private const OWNER_NAME = 'Monir Hossain Bappy';

    private const WEBSITE = 'seamanstore.com';

    /**
     * The spec's explicit asks (product_variants, suppliers, purchases,
     * accounts, reports; credit sales/customer due is core, not a flag —
     * verified: customers.due + DuePayment flow carry no feature gate)
     * plus the everyday set every Baby Park shop runs with. Deliberately
     * NOT granted: shared_catalog/shared_stock_pool (no branch),
     * online_business, article_series (serial barcodes are Baby Park's
     * scheme; Seaman's labels come in a later phase of its own plan).
     */
    private const FEATURE_KEYS = [
        'memo_whatsapp', 'memo_print', 'reports', 'expenses', 'accounts',
        'export', 'barcode_printing', 'purchases', 'returns', 'damages',
        'stock_count', 'product_variants', 'activity_log', 'suppliers',
        'low_stock_alerts', 'shop_backup', 'cashier_management',
        'sub_categories', // Phase 2 — category → sub-category, this shop only
        'variant_options', // Phase 3 — named option axes + size sets, this shop only
        'brands', // Phase 4 — brand per product/variant, Nil display, brand report
        'weighted_average_costing', // Phase 4 — variant stock-in averages cost instead of overwriting
        'parties', // Phase 5 — corporate parties, bill-wise dues, A4 statements + PDF + WhatsApp
        'opening_stock', // Phase 6B — the stock-entry grid + bulk sheet
    ];

    /**
     * The size sets straight off the owner's handwritten note — seeded
     * idempotently (updateOrCreate by name) so a re-run refreshes rather
     * than duplicates. Half sizes are stored as "20.5" and displayed
     * "20½" (resources/js/support/sizeDisplay.js).
     */
    private const SIZE_SETS = [
        'Letter (S–4XL)' => ['S', 'M', 'L', 'XL', 'XXL', 'XXXL', '4XL'],
        'T-Shirt (M–XXXL)' => ['M', 'L', 'XL', 'XXL', 'XXXL'],
        'Shoe 39–44' => ['39', '40', '41', '42', '43', '44'],
        'Shoe 40–44' => ['40', '41', '42', '43', '44'],
        'Shoe 39–45' => ['39', '40', '41', '42', '43', '44', '45'],
        'Shoe 40–46' => ['40', '41', '42', '43', '44', '45', '46'],
        'Shoe 45–48' => ['45', '46', '47', '48'],
        'Pant waist 27–45' => ['27', '28', '29', '30', '31', '32', '33', '34', '35', '36', '37', '38', '39', '40', '41', '42', '43', '44', '45'],
        'Paijama 38–46' => ['38', '40', '42', '44', '46'],
        'Peak cap 20½–23' => ['20.5', '21', '21.5', '22', '22.5', '23'],
    ];

    public function handle(): int
    {
        if ($this->option('rollback')) {
            return $this->rollback();
        }

        $isDryRun = (bool) $this->option('dry-run');
        $existing = Shop::withoutGlobalScopes()->where('phone', self::OWNER_PHONE)->first();

        $this->info('--- SEAMAN SHOE STORE সেটআপ ---');
        $this->line('Shop: '.($existing ? "আগেই আছে (ID {$existing->id})" : 'নতুন তৈরি হবে'));
        $this->line('Business type: footwear (Baby Park-এর প্রমাণিত ভার্টিক্যাল — ভ্যারিয়েন্ট+সাধারণ দুই রকম পণ্যই POS-এ চলে)');
        $this->line('ফিচার: '.implode(', ', self::FEATURE_KEYS));

        if ($isDryRun) {
            $this->newLine();
            $this->info('এটা শুধু প্রিভিউ — কিছু লেখা হয়নি। ঠিক থাকলে --dry-run ছাড়া আবার চালান।');

            return self::SUCCESS;
        }

        $password = $this->option('password') ?: Str::password(12);
        $passwordWasGenerated = ! $this->option('password');
        $shopWasCreated = ! $existing;

        $shop = DB::transaction(function () use ($existing, $password) {
            // Same defensive re-seed as SetupBabyPark: every Feature row
            // this command grants must exist on this environment.
            (new FeatureSeeder)->run();

            BusinessType::updateOrCreate(
                ['slug' => 'footwear'],
                array_merge(config('business_types.footwear'), ['is_active' => true])
            );

            if ($existing) {
                $existing->features()->sync(Feature::whereIn('key', self::FEATURE_KEYS)->pluck('id'));
                $existing->update([
                    'website' => $existing->website ?: self::WEBSITE,
                    'owner_name' => $existing->owner_name ?: self::OWNER_NAME,
                ]);

                // an explicitly GIVEN password on a re-run is a deliberate
                // reset; a generated one is not applied anywhere and must
                // never be printed as if it were (live: the re-run showed a
                // fresh random password that opened nothing)
                if ($this->option('password')) {
                    User::where('shop_id', $existing->id)->where('role', 'owner')
                        ->update(['password' => bcrypt($this->option('password'))]);
                }

                $this->seedSizeSets($existing->id);

                return $existing;
            }

            $shop = ShopProvisioner::provision(
                [
                    'name' => self::SHOP_NAME,
                    'name_en' => self::SHOP_NAME,
                    'phone' => self::OWNER_PHONE,
                    'owner_name' => self::OWNER_NAME,
                    'website' => self::WEBSITE,
                    'business_type_id' => BusinessType::where('slug', 'footwear')->value('id'),
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
                    'onboarded_at' => now(),
                ],
                self::OWNER_NAME,
                self::OWNER_PHONE,
                null, // no email — owner has none
                $password,
                self::FEATURE_KEYS
            );

            $this->seedSizeSets($shop->id);

            return $shop;
        });

        $this->newLine();
        $this->info('✅ SEAMAN SHOE STORE সেটআপ সম্পন্ন।');
        $this->line("Shop ID: {$shop->id} (লগইন ফোন: ".self::OWNER_PHONE.')');
        $this->line('Plan: monthly · account_type: paid · status: active · মেয়াদ: '.$shop->subscription_expiry.' (নবায়ন Admin > Subscription দিয়ে, Baby Park-এর মতোই)');
        if ($shopWasCreated && $passwordWasGenerated) {
            $this->warn("পাসওয়ার্ড (এখনই সেভ করুন, আর দেখানো হবে না): {$password}");
        } elseif (! $passwordWasGenerated) {
            $this->info('পাসওয়ার্ড দেওয়া অপশন থেকে সেট হয়েছে।');
        } else {
            $this->line('পাসওয়ার্ড বদলায়নি — আগেরটাই চালু। বদলাতে চাইলে --password= দিয়ে চালান।');
        }

        return self::SUCCESS;
    }

    private function seedSizeSets(int $shopId): void
    {
        foreach (self::SIZE_SETS as $name => $sizes) {
            \App\Models\SizeSet::withoutGlobalScopes()->updateOrCreate(
                ['shop_id' => $shopId, 'name' => $name],
                ['sizes' => $sizes]
            );
        }
    }

    private function rollback(): int
    {
        $shop = Shop::withoutGlobalScopes()->where('phone', self::OWNER_PHONE)->first();
        if (! $shop) {
            $this->info('কোনো SEAMAN শপ পাওয়া যায়নি — করার কিছু নেই।');

            return self::SUCCESS;
        }

        $saleCount = Sale::withoutGlobalScopes()->where('shop_id', $shop->id)->count();
        if ($saleCount > 0) {
            $this->error("রোলব্যাক বন্ধ করা হলো — {$saleCount}টা বিক্রি হয়ে গেছে। এখন আর নিরাপদে ফিরিয়ে নেওয়া যাবে না।");

            return self::FAILURE;
        }

        DB::transaction(function () use ($shop) {
            Product::withoutGlobalScopes()->where('shop_id', $shop->id)->forceDelete();
            ProductCategory::withoutGlobalScopes()->where('shop_id', $shop->id)->delete();
            \App\Models\SizeSet::withoutGlobalScopes()->where('shop_id', $shop->id)->delete();
            Unit::withoutGlobalScopes()->where('shop_id', $shop->id)->delete();
            User::where('shop_id', $shop->id)->delete();
            $shop->features()->detach();
            $shop->delete();
        });

        $this->info('✅ SEAMAN শপ মুছে ফেলা হয়েছে।');

        return self::SUCCESS;
    }
}
