<?php

namespace App\Console\Commands;

use App\Models\BusinessType;
use App\Models\Feature;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\ProductVariant;
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
    protected $signature = 'shop:setup-demo-clothing {--phone=01799000088} {--password=} {--qty=20} {--dry-run} {--rollback}';

    protected $description = 'Idempotent demo clothing shop (article-wise catalog, color+size variants, stocked). --rollback removes it (refuses if any sale exists).';

    private const SHOP_NAME = 'ডেমো শপ - কাপড়';

    private const FEATURE_KEYS = [
        'memo_whatsapp', 'memo_print', 'reports', 'expenses', 'accounts',
        'export', 'barcode_printing', 'purchases', 'returns', 'damages',
        'stock_count', 'product_variants', 'activity_log', 'suppliers',
        'low_stock_alerts', 'cashier_management', 'article_series',
    ];

    /** category name => [emoji, article code, serial start, sizes (empty = plain product), colors, cost, price] */
    private const CATALOG = [
        'প্যান্ট' => ['👖', 'P', 10001, ['28', '30', '32', '34', '36'], ['কালো', 'নেভি ব্লু'], 450, 850],
        'শার্ট' => ['👔', 'S', 10001, ['M', 'L', 'XL', 'XXL'], ['সাদা', 'আকাশি'], 380, 750],
        'গেঞ্জি' => ['👕', 'T', 10001, ['M', 'L', 'XL'], ['কালো', 'সাদা'], 180, 350],
        'আন্ডারওয়্যার' => ['🩲', 'U', 10001, ['M', 'L', 'XL'], ['কালো', 'ধূসর'], 80, 180],
        'পাঞ্জাবি' => ['🥻', 'J', 10001, ['M', 'L', 'XL', 'XXL'], ['সাদা', 'মেরুন'], 650, 1250],
        'পায়জামা' => ['👘', 'Y', 10001, ['38', '40', '42', '44'], ['সাদা', 'ক্রিম'], 280, 550],
        'মেয়েদের জামা' => ['👗', 'M', 10001, ['32', '34', '36', '38'], ['গোলাপি', 'লাল'], 420, 950],
        'থ্রি-পিস' => ['🧕', 'R', 10001, ['ফ্রি সাইজ'], ['নীল', 'সবুজ', 'মেরুন'], 900, 1850],
        'কাপড় (পিস)' => ['🧵', 'K', 10001, [], [], 220, 420],
    ];

    private const ARTICLES_PER_CATEGORY = 3;

    /** barcode-safe latin code per Bengali color name */
    private const COLOR_CODES = [
        'কালো' => 'BK', 'সাদা' => 'WT', 'নেভি ব্লু' => 'NV', 'আকাশি' => 'SKY',
        'ধূসর' => 'GR', 'মেরুন' => 'MR', 'ক্রিম' => 'CRM', 'গোলাপি' => 'PK',
        'লাল' => 'RD', 'নীল' => 'BL', 'সবুজ' => 'GRN',
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
        $this->line('ক্যাটাগরি: '.implode(', ', array_keys(self::CATALOG)));
        $this->line('প্রতি ক্যাটাগরিতে '.self::ARTICLES_PER_CATEGORY.'টা আর্টিকেল, প্রতিটা ভ্যারিয়েন্টে স্টক '.$qty);

        if ($this->option('dry-run')) {
            $this->newLine();
            $this->info('এটা শুধু প্রিভিউ — কিছু লেখা হয়নি।');

            return self::SUCCESS;
        }

        $password = $this->option('password') ?: Str::password(10);
        $passwordWasGenerated = ! $this->option('password');
        $shopWasCreated = ! $existing;

        $shop = DB::transaction(function () use ($existing, $phone, $password, $qty) {
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

            $this->seedCatalog($shop, $qty);

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

    private function seedCatalog(Shop $shop, float $qty): void
    {
        $pieceUnitId = Unit::withoutGlobalScopes()->where('shop_id', $shop->id)
            ->where('name', 'like', '%পিস%')->value('id');

        foreach (self::CATALOG as $categoryName => [$emoji, $code, $serialStart, $sizes, $colors, $cost, $price]) {
            $category = ProductCategory::withoutGlobalScopes()->updateOrCreate(
                ['shop_id' => $shop->id, 'name' => $categoryName],
                ['emoji' => $emoji, 'code' => $code, 'serial_start' => $serialStart]
            );

            for ($i = 0; $i < self::ARTICLES_PER_CATEGORY; $i++) {
                $articleNo = $code.'-'.($serialStart + $i);
                $hasVariants = $sizes !== [] && $colors !== [];
                // a little price spread between articles so the demo's
                // reports/bills don't show one identical number everywhere
                $articleCost = $cost + $i * 30;
                $articlePrice = $price + $i * 50;

                $product = Product::withoutGlobalScopes()->updateOrCreate(
                    ['shop_id' => $shop->id, 'name' => $articleNo],
                    [
                        'category_id' => $category->id,
                        'unit_id' => $pieceUnitId,
                        'emoji' => $emoji,
                        'barcode' => 'DK-'.$articleNo,
                        'cost' => $articleCost,
                        'price' => $articlePrice,
                        'stock' => 0,
                        'deleted_at' => null,
                    ]
                );

                if (! $hasVariants) {
                    Product::withoutGlobalScopes()->whereKey($product->id)->update(['stock' => $qty * 2]);

                    continue;
                }

                foreach ($colors as $color) {
                    $colorCode = self::COLOR_CODES[$color] ?? Str::upper(Str::limit(Str::ascii($color), 3, ''));
                    foreach ($sizes as $size) {
                        ProductVariant::withoutGlobalScopes()->updateOrCreate(
                            ['shop_id' => $shop->id, 'product_id' => $product->id, 'size' => $size, 'color' => $color],
                            [
                                'barcode' => 'DK-'.$articleNo.'-'.$colorCode.'-'.Str::upper(Str::ascii($size)),
                                'price' => $articlePrice,
                                'cost' => $articleCost,
                                'stock' => $qty,
                                'deleted_at' => null,
                            ]
                        );
                    }
                }

                $sum = ProductVariant::withoutGlobalScopes()
                    ->where('product_id', $product->id)->whereNull('deleted_at')->sum('stock');
                Product::withoutGlobalScopes()->whereKey($product->id)->update(['stock' => $sum]);
            }
        }
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
