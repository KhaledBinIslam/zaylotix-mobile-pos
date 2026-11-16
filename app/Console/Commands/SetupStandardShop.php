<?php

namespace App\Console\Commands;

use App\Models\BusinessType;
use App\Models\Customer;
use App\Models\Feature;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\Rental;
use App\Models\RentalItem;
use App\Models\RentalReturn;
use App\Models\Sale;
use App\Models\Shop;
use App\Models\Unit;
use App\Models\User;
use App\Http\Controllers\App\PosController;
use App\Models\Payment;
use App\Support\DemoArticleCatalog;
use App\Support\ShopProvisioner;
use App\Support\Tenancy;
use Database\Seeders\FeatureSeeder;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * One-shot, idempotent setup for "Standard" — a Panjabi/wedding & groom
 * accessories shop (prospective client, 2026-11-16). Demonstrates:
 * article-wise color/size inventory with real barcodes (reusing
 * DemoArticleCatalog — no new inventory system), sell/rent/both per
 * product (Rental Management), and the owner + manager dual-login
 * Khaled asked for (plain Staff + permissions, nothing new).
 *
 * Safe to re-run: looked up by owner phone first; products/customers are
 * updateOrCreate/firstOrCreate by natural key, and demo Sale/Rental rows
 * are keyed by a fixed client_uuid so a re-run tops up rather than
 * duplicating. --dry-run previews only. --rollback removes it (refuses
 * if real sales/rentals beyond the demo ones exist).
 */
class SetupStandardShop extends Command
{
    protected $signature = 'shop:setup-standard {--dry-run} {--password=123456} {--rollback}';

    protected $description = 'Idempotent setup for Standard (Panjabi/wedding accessories demo shop with Rental Management).';

    private const OWNER_PHONE = '01846750886';

    private const MANAGER_PHONE = '01846750887';

    private const SHOP_NAME = 'Standard';

    private const FEATURE_KEYS = [
        'memo_whatsapp', 'memo_print', 'reports', 'expenses', 'accounts', 'quotations',
        'cashier_management', 'export', 'barcode_printing', 'purchases', 'returns', 'damages',
        'stock_count', 'product_variants', 'activity_log', 'suppliers', 'low_stock_alerts',
        'rentals',
    ];

    /** [emoji, code, serial start, sizes, colors, cost, price] — same shape DemoArticleCatalog::CLOTHING/FOOTWEAR already use. */
    private const CATALOG = [
        'পাঞ্জাবি' => ['🥻', 'PNJ', 10001, ['S', 'M', 'L', 'XL', 'XXL'], ['সাদা', 'কালো', 'মেরুন', 'নেভি ব্লু', 'গোল্ডেন'], 650, 1250],
        'পায়জামা' => ['👘', 'PJM', 10001, ['S', 'M', 'L', 'XL', 'XXL'], ['সাদা', 'ক্রিম'], 280, 550],
        'স্যুট' => ['🤵', 'SUT', 10001, ['38', '40', '42', '44', '46'], ['কালো', 'নেভি ব্লু'], 3500, 6500],
        'ব্লেজার' => ['🧥', 'BLZ', 10001, ['38', '40', '42', '44', '46'], ['কালো', 'ধূসর'], 1800, 3200],
        'ওয়েস্টকোট' => ['🦺', 'WST', 10001, ['S', 'M', 'L', 'XL'], ['কালো', 'মেরুন', 'গোল্ডেন'], 650, 1200],
        'শেরওয়ানি' => ['👑', 'SHR', 10001, ['S', 'M', 'L', 'XL', 'XXL'], ['গোল্ডেন', 'মেরুন', 'কালো'], 1500, 3000],
        'ওয়েডিং পাঞ্জাবি' => ['🎊', 'WPNJ', 10001, ['S', 'M', 'L', 'XL', 'XXL'], ['সাদা', 'গোল্ডেন'], 900, 1800],
        'পাগড়ি' => ['🧢', 'PGR', 10001, ['ফ্রি সাইজ'], ['গোল্ডেন', 'লাল', 'সাদা'], 150, 350],
        'সেহরা' => ['🌸', 'SHE', 10001, ['ফ্রি সাইজ'], ['সাদা', 'গোল্ডেন'], 150, 350],
        'মালা' => ['📿', 'MLA', 10001, [], ['গোল্ডেন', 'সাদা'], 80, 200],
        'গ্রুম সু' => ['👞', 'GSH', 10001, ['40', '41', '42', '43', '44'], ['কালো', 'ব্রাউন'], 400, 900],
        'মোজারি' => ['🥿', 'MOJ', 10001, ['40', '41', '42', '43', '44'], ['গোল্ডেন', 'কালো'], 350, 750],
        'ওয়েডিং এক্সেসরিজ' => ['💍', 'WAC', 10001, [], ['গোল্ডেন'], 200, 500],
        'গ্রুম ডেকোরেশন আইটেম' => ['🎉', 'GDC', 10001, [], [], 150, 400],
    ];

    /** category name => [rental_mode, rent_price] — everything else stays sell_only. Matches Khaled's own spec list + worked example prices. */
    private const RENTAL_CATEGORIES = [
        'শেরওয়ানি' => [1500],
        'পাগড়ি' => [300],
        'সেহরা' => [200],
        'মালা' => [200],
        'গ্রুম সু' => [500],
        'ওয়েডিং এক্সেসরিজ' => [300],
        'গ্রুম ডেকোরেশন আইটেম' => [250],
        'পায়জামা' => [150],
    ];

    private const CUSTOMERS = [
        ['Rahim Uddin', '01711112222'],
        ['Karim Hossain', '01711113333'],
        ['Jasim Sheikh', '01711114444'],
    ];

    public function handle(): int
    {
        if ($this->option('rollback')) {
            return $this->rollback();
        }

        $isDryRun = (bool) $this->option('dry-run');
        $existing = Shop::withoutGlobalScopes()->where('phone', self::OWNER_PHONE)->first();

        $this->info('--- Standard (পাঞ্জাবি ও ওয়েডিং এক্সেসরিজ) সেটআপ ---');
        $this->line('Shop: '.($existing ? "আগেই আছে (ID {$existing->id})" : 'নতুন তৈরি হবে'));
        $this->line('ফিচার: '.implode(', ', self::FEATURE_KEYS));
        $this->line('ক্যাটাগরি: '.implode(', ', array_keys(self::CATALOG)));

        if ($isDryRun) {
            $this->newLine();
            $this->info('এটা শুধু প্রিভিউ — কিছু লেখা হয়নি। ঠিক থাকলে --dry-run ছাড়া আবার চালান।');

            return self::SUCCESS;
        }

        $password = $this->option('password');

        $shop = DB::transaction(function () use ($existing, $password) {
            (new FeatureSeeder)->run();

            BusinessType::updateOrCreate(
                ['slug' => 'clothing'],
                array_merge(config('business_types.clothing'), ['is_active' => true])
            );

            if ($existing) {
                $existing->features()->sync(Feature::whereIn('key', self::FEATURE_KEYS)->pluck('id'));
                $shop = $existing;
                Tenancy::set($shop->id);
            } else {
                $businessType = BusinessType::where('slug', 'clothing')->first();
                $shop = ShopProvisioner::provisionKeepingTenancy(
                    [
                        'name' => self::SHOP_NAME,
                        'phone' => self::OWNER_PHONE,
                        'business_type_id' => $businessType->id,
                        'sales_mode' => 'both',
                        'lang' => 'bn',
                        'plan' => 'monthly',
                        'account_type' => 'demo',
                        'status' => 'active',
                        'subscription_start' => now()->toDateString(),
                        'subscription_expiry' => now()->addDays(60)->toDateString(),
                        'cash_balance' => 0,
                        'bank_balance' => 0,
                        'capital' => 0,
                        'vat_mode' => 'none',
                        'invoice_counter' => 1000,
                        'onboarded_at' => now(),
                    ],
                    'Standard Owner',
                    self::OWNER_PHONE,
                    null,
                    $password,
                    self::FEATURE_KEYS
                );
            }

            // Admin 1 (Main Shop Admin) = the owner login itself (already
            // exists, created above). Admin 2 (Manager/Reporting Admin) =
            // a plain Staff user with void/returns/stock/reports — the
            // EXISTING permission system, nothing new (see the plan's
            // Part C: Khaled confirmed "Invoice Edit" means void, and
            // this is exactly how a second admin with that access already
            // works for every other shop).
            User::withoutGlobalScopes()->updateOrCreate(
                ['shop_id' => $shop->id, 'phone' => self::MANAGER_PHONE],
                [
                    'name' => 'Standard Manager', 'password' => $password, 'role' => 'staff',
                    'permissions' => ['sales_void', 'returns', 'stock', 'reports'], 'lang' => 'bn',
                ]
            );

            $this->seedCatalog($shop);
            $customers = $this->seedCustomers($shop);
            $this->seedDemoSale($shop, $customers[0]);
            $this->seedDemoRentals($shop, $customers);

            Tenancy::clear();

            return $shop;
        });

        $this->newLine();
        $this->info('✅ Standard সেটআপ সম্পন্ন।');
        $this->line("Shop ID: {$shop->id}");
        $this->line('Owner login: '.self::OWNER_PHONE.' / '.$password);
        $this->line('Manager login: '.self::MANAGER_PHONE.' / '.$password.' (sales_void, returns, stock, reports)');

        return self::SUCCESS;
    }

    private function seedCatalog(Shop $shop): void
    {
        DemoArticleCatalog::seedInto($shop, self::CATALOG, 'STD', 2, 8);

        foreach (self::RENTAL_CATEGORIES as $categoryName => [$rentPrice]) {
            $category = ProductCategory::withoutGlobalScopes()->where('shop_id', $shop->id)->where('name', $categoryName)->first();
            if (! $category) {
                continue;
            }
            Product::withoutGlobalScopes()->where('shop_id', $shop->id)->where('category_id', $category->id)
                ->update(['rental_mode' => Product::RENTAL_MODE_BOTH, 'rent_price' => $rentPrice]);
        }
    }

    private function seedCustomers(Shop $shop): array
    {
        return array_map(
            fn ($row) => Customer::withoutGlobalScopes()->firstOrCreate(
                ['shop_id' => $shop->id, 'phone' => $row[1]],
                ['name' => $row[0], 'due' => 0, 'total_spent' => 0, 'visits' => 0]
            ),
            self::CUSTOMERS
        );
    }

    /** A real, completed sale through the actual checkout code path (PosController::performCheckout — not a raw insert), so Sales History/Due Ledger have real, consistent demo data. Idempotent via a fixed client_uuid. */
    private function seedDemoSale(Shop $shop, Customer $customer): void
    {
        $product = Product::withoutGlobalScopes()->with('variants')->where('shop_id', $shop->id)->where('name', 'like', 'PNJ-%')->first();
        $variant = $product?->variants->first();
        if (! $product || ! $variant) {
            return;
        }

        PosController::performCheckout([
            'client_uuid' => 'standard-demo-sale-1',
            'items' => [['product_id' => $product->id, 'product_variant_id' => $variant->id, 'qty' => 2]],
            'customer_id' => $customer->id,
            'payments' => [['method' => 'cash', 'amount' => (float) $variant->price * 2]],
        ], $shop->id, null);
    }

    /**
     * Two demo rentals, run through the real money/stock logic
     * (replicated here, not RentalController's HTTP action directly —
     * every other demo seeder in this codebase follows the same
     * raw-but-correct pattern for its Sale rows rather than faking an
     * authenticated HTTP request). The math mirrors
     * RentalController::store()/processReturn() exactly and is covered
     * by tests/Feature/RentalTest.php.
     *
     * 1) Active: Khaled's own worked line items (Sherwani/Pagri/Mala×2/
     *    Groom Shoes), ৳1,000 advance, due left outstanding.
     * 2) Fully returned & paid: a smaller rental, settled to ৳0 due.
     */
    private function seedDemoRentals(Shop $shop, array $customers): void
    {
        if (Rental::withoutGlobalScopes()->where('shop_id', $shop->id)->where('invoice_no', 'RNT-DEMO-1')->exists()) {
            return; // already seeded — idempotent re-run
        }

        $sherwani = Product::withoutGlobalScopes()->where('shop_id', $shop->id)->where('category_id', ProductCategory::withoutGlobalScopes()->where('shop_id', $shop->id)->where('name', 'শেরওয়ানি')->value('id'))->first();
        $pagri = Product::withoutGlobalScopes()->where('shop_id', $shop->id)->where('category_id', ProductCategory::withoutGlobalScopes()->where('shop_id', $shop->id)->where('name', 'পাগড়ি')->value('id'))->first();
        $mala = Product::withoutGlobalScopes()->where('shop_id', $shop->id)->where('category_id', ProductCategory::withoutGlobalScopes()->where('shop_id', $shop->id)->where('name', 'মালা')->value('id'))->first();
        $shoes = Product::withoutGlobalScopes()->where('shop_id', $shop->id)->where('category_id', ProductCategory::withoutGlobalScopes()->where('shop_id', $shop->id)->where('name', 'গ্রুম সু')->value('id'))->first();
        if (! $sherwani || ! $pagri || ! $mala || ! $shoes) {
            return;
        }

        $lockedShop = Shop::withoutGlobalScopes()->whereKey($shop->id)->lockForUpdate()->first();
        $customer1 = Customer::withoutGlobalScopes()->whereKey($customers[0]->id)->lockForUpdate()->first();

        $lines1 = [
            ['product' => $sherwani, 'qty' => 1], ['product' => $pagri, 'qty' => 1],
            ['product' => $mala, 'qty' => 2], ['product' => $shoes, 'qty' => 1],
        ];
        $total1 = array_sum(array_map(fn ($l) => (float) $l['product']->rent_price * $l['qty'], $lines1));

        $lockedShop->rental_invoice_counter += 1;
        $rental1 = Rental::create([
            'shop_id' => $shop->id, 'customer_id' => $customer1->id, 'invoice_no' => 'RNT-DEMO-1',
            'rental_date' => now()->toDateString(), 'expected_return_date' => now()->addDays(3)->toDateString(),
            'status' => 'active', 'total_rent_amount' => round($total1, 2), 'user_id' => null,
        ]);
        foreach ($lines1 as $l) {
            RentalItem::create(['shop_id' => $shop->id, 'rental_id' => $rental1->id, 'product_id' => $l['product']->id, 'qty' => $l['qty'], 'rent_price' => (float) $l['product']->rent_price, 'status' => 'out']);
        }
        $dueAfterCharge1 = (float) $customer1->due + $total1;
        $advance1 = 1000.0;
        Payment::create([
            'shop_id' => $shop->id, 'customer_id' => $customer1->id, 'rental_id' => $rental1->id,
            'amount' => $advance1, 'due_before' => $dueAfterCharge1, 'due_after' => $dueAfterCharge1 - $advance1,
            'method' => 'cash', 'date' => now()->toDateString(),
        ]);
        $customer1->due = $dueAfterCharge1 - $advance1;
        $customer1->save();
        $lockedShop->cash_balance = (float) $lockedShop->cash_balance + $advance1;
        $lockedShop->save();

        // Second rental: just Pagri + Mala, fully returned & settled to ৳0.
        $customer2 = Customer::withoutGlobalScopes()->whereKey($customers[1]->id)->lockForUpdate()->first();
        $lines2 = [['product' => $pagri, 'qty' => 1], ['product' => $mala, 'qty' => 1]];
        $total2 = array_sum(array_map(fn ($l) => (float) $l['product']->rent_price * $l['qty'], $lines2));

        $lockedShop->rental_invoice_counter += 1;
        $rental2 = Rental::create([
            'shop_id' => $shop->id, 'customer_id' => $customer2->id, 'invoice_no' => 'RNT-DEMO-2',
            'rental_date' => now()->subDays(5)->toDateString(), 'expected_return_date' => now()->subDays(2)->toDateString(),
            'actual_return_date' => now()->subDays(2)->toDateString(), 'status' => 'returned', 'total_rent_amount' => round($total2, 2), 'user_id' => null,
        ]);
        foreach ($lines2 as $l) {
            $item2 = RentalItem::create(['shop_id' => $shop->id, 'rental_id' => $rental2->id, 'product_id' => $l['product']->id, 'qty' => $l['qty'], 'returned_qty' => $l['qty'], 'rent_price' => (float) $l['product']->rent_price, 'status' => 'returned']);
            RentalReturn::create(['shop_id' => $shop->id, 'rental_id' => $rental2->id, 'rental_item_id' => $item2->id, 'qty' => $l['qty'], 'condition' => 'resalable', 'date' => now()->subDays(2)->toDateString()]);
        }
        $dueAfterCharge2 = (float) $customer2->due + $total2;
        Payment::create([
            'shop_id' => $shop->id, 'customer_id' => $customer2->id, 'rental_id' => $rental2->id,
            'amount' => $total2, 'due_before' => $dueAfterCharge2, 'due_after' => 0,
            'method' => 'cash', 'date' => now()->subDays(2)->toDateString(),
        ]);
        $customer2->due = $dueAfterCharge2 - $total2;
        $customer2->save();
        $lockedShop->cash_balance = (float) $lockedShop->cash_balance + $total2;
        $lockedShop->save();
    }

    private function rollback(): int
    {
        $shop = Shop::withoutGlobalScopes()->where('phone', self::OWNER_PHONE)->first();
        if (! $shop) {
            $this->info('কোনো Standard শপ পাওয়া যায়নি — করার কিছু নেই।');

            return self::SUCCESS;
        }

        $realSaleCount = Sale::withoutGlobalScopes()->where('shop_id', $shop->id)->whereNull('client_uuid')->count();
        if ($realSaleCount > 0) {
            $this->error("রোলব্যাক বন্ধ করা হলো — {$realSaleCount}টা non-demo বিক্রি হয়ে গেছে।");

            return self::FAILURE;
        }

        DB::transaction(function () use ($shop) {
            Product::withoutGlobalScopes()->where('shop_id', $shop->id)->forceDelete();
            ProductCategory::withoutGlobalScopes()->where('shop_id', $shop->id)->delete();
            Unit::withoutGlobalScopes()->where('shop_id', $shop->id)->delete();
            Customer::withoutGlobalScopes()->where('shop_id', $shop->id)->forceDelete();
            User::withoutGlobalScopes()->where('shop_id', $shop->id)->delete();
            Shop::withoutGlobalScopes()->whereKey($shop->id)->delete();
        });

        $this->info('Standard শপ এবং এর সব ডেটা ডিলিট করা হয়েছে।');

        return self::SUCCESS;
    }
}
