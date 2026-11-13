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
 * Khaled: "arekta kaporer dokan er demo koro... gents pant shirt genji
 * underwear panjabi paijama, meyeder jama three-piece, kapor pc — sob
 * serial/article wise, color size onujai, amar polapain demo dekhate
 * pare moto." A ready-made clothing-store demo the sales team can run a
 * full sell on: article-numbered products (Baby Park's P-10001 naming
 * convention), each with real color+size variants, scannable variant
 * barcodes, and stock already on the shelf.
 *
 * Mirrors SetupBabyPark/SetupSeamanShop's one-shot idempotent pattern:
 * keyed by the owner phone, a re-run re-syncs features and tops the
 * catalog back up instead of duplicating anything.
 */
class SetupDemoClothingShop extends Command
{
    protected $signature = 'shop:setup-demo-clothing {--phone=01799000088} {--password=} {--qty=20} {--articles=6} {--dry-run} {--rollback}';

    protected $description = 'Idempotent demo clothing shop (article-wise catalog, color+size variants, stocked). --rollback removes it (refuses if any sale exists).';

    private const SHOP_NAME = 'ডেমো শপ - কাপড়';

    private const FEATURE_KEYS = [
        'memo_whatsapp', 'memo_print', 'reports', 'expenses', 'accounts',
        'export', 'barcode_printing', 'purchases', 'returns', 'damages',
        'stock_count', 'product_variants', 'activity_log', 'suppliers',
        'low_stock_alerts', 'cashier_management', 'article_series',
    ];

    public function handle(): int
    {
        $phone = trim((string) $this->option('phone'));
        $qty = (float) $this->option('qty');

        if ($this->option('rollback')) {
            return $this->rollback($phone);
        }

        $existing = Shop::withoutGlobalScopes()->where('phone', $phone)->first();

        $this->info('--- '.self::SHOP_NAME.' সেটআপ ---');
        $this->line('Shop: '.($existing ? "আগেই আছে (ID {$existing->id})" : "নতুন তৈরি হবে (ফোন: {$phone})"));
        $articles = max(1, (int) $this->option('articles'));
        $this->line('ক্যাটাগরি: '.implode(', ', array_keys(\App\Support\DemoArticleCatalog::CLOTHING)));
        $this->line("প্রতি ক্যাটাগরিতে {$articles}টা আর্টিকেল, প্রতিটা ভ্যারিয়েন্টে স্টক {$qty}");

        if ($this->option('dry-run')) {
            $this->newLine();
            $this->info('এটা শুধু প্রিভিউ — কিছু লেখা হয়নি।');

            return self::SUCCESS;
        }

        $password = $this->option('password') ?: Str::password(10);
        $passwordWasGenerated = ! $this->option('password');
        $shopWasCreated = ! $existing;

        $shop = DB::transaction(function () use ($existing, $phone, $password, $qty, $articles) {
            (new FeatureSeeder)->run();

            BusinessType::updateOrCreate(
                ['slug' => 'clothing'],
                array_merge(config('business_types.clothing', ['label_bn' => 'কাপড়ের দোকান', 'label_en' => 'Clothing']), ['is_active' => true])
            );

            if ($existing) {
                $shop = $existing;
                $shop->features()->sync(Feature::whereIn('key', self::FEATURE_KEYS)->pluck('id'));
                if ($this->option('password')) {
                    User::where('shop_id', $shop->id)->where('role', 'owner')->update(['password' => bcrypt($this->option('password'))]);
                }
            } else {
                $shop = ShopProvisioner::provision(
                    [
                        'name' => self::SHOP_NAME,
                        'name_en' => 'Demo Clothing Shop',
                        'phone' => $phone,
                        'owner_name' => 'ডেমো ওনার',
                        'business_type_id' => BusinessType::where('slug', 'clothing')->value('id'),
                        'sales_mode' => 'both',
                        'lang' => 'bn',
                        'plan' => 'monthly',
                        'account_type' => 'demo',
                        'status' => 'active',
                        'subscription_start' => now()->toDateString(),
                        'subscription_expiry' => now()->addYear()->toDateString(),
                        'cash_balance' => 0, 'bank_balance' => 0, 'capital' => 0,
                        'vat_mode' => 'none', 'invoice_counter' => 1000,
                        'onboarded_at' => now(),
                    ],
                    'ডেমো ওনার', $phone, null, $password, self::FEATURE_KEYS
                );

                // the business-type's generic seed categories would sit
                // beside the article-coded ones below as dead weight in a
                // demo — fresh shop, no products yet, safe to clear
                ProductCategory::withoutGlobalScopes()->where('shop_id', $shop->id)->delete();
            }

            \App\Support\DemoArticleCatalog::seedInto($shop, \App\Support\DemoArticleCatalog::CLOTHING, 'DK', $articles, $qty);

            return $shop;
        });

        $this->newLine();
        $this->info('✅ '.self::SHOP_NAME.' সেটআপ সম্পন্ন।');
        $this->line("Shop ID: {$shop->id} · লগইন ফোন: {$phone}");
        if ($shopWasCreated && $passwordWasGenerated) {
            $this->warn("পাসওয়ার্ড (এখনই সেভ করুন): {$password}");
        } elseif (! $passwordWasGenerated) {
            $this->info('পাসওয়ার্ড দেওয়া অপশন থেকে সেট হয়েছে।');
        } else {
            $this->line('পাসওয়ার্ড বদলায়নি — আগেরটাই চালু।');
        }

        return self::SUCCESS;
    }

    private function rollback(string $phone): int
    {
        $shop = Shop::withoutGlobalScopes()->where('phone', $phone)->first();
        if (! $shop) {
            $this->info('কোনো ডেমো কাপড়ের শপ পাওয়া যায়নি — করার কিছু নেই।');

            return self::SUCCESS;
        }

        $saleCount = Sale::withoutGlobalScopes()->where('shop_id', $shop->id)->count();
        if ($saleCount > 0) {
            $this->error("রোলব্যাক বন্ধ — {$saleCount}টা বিক্রি হয়ে গেছে।");

            return self::FAILURE;
        }

        DB::transaction(function () use ($shop) {
            Product::withoutGlobalScopes()->where('shop_id', $shop->id)->forceDelete();
            ProductCategory::withoutGlobalScopes()->where('shop_id', $shop->id)->delete();
            Unit::withoutGlobalScopes()->where('shop_id', $shop->id)->delete();
            User::where('shop_id', $shop->id)->delete();
            $shop->features()->detach();
            $shop->delete();
        });

        $this->info('✅ ডেমো কাপড়ের শপ মুছে ফেলা হয়েছে।');

        return self::SUCCESS;
    }
}
