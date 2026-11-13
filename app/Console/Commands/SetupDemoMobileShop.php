<?php

namespace App\Console\Commands;

use App\Models\BusinessType;
use App\Models\Customer;
use App\Models\Expense;
use App\Models\ExpenseCategory;
use App\Models\Feature;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\ProductSerial;
use App\Models\Purchase;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\Shop;
use App\Models\Supplier;
use App\Models\Unit;
use App\Models\User;
use App\Support\ShopProvisioner;
use Database\Seeders\FeatureSeeder;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * "Mobile City Center" — a full, realistic BD mobile-phone + accessories
 * demo shop for the sales ambassadors: phones carry individually tracked
 * serials with Luhn-valid IMEIs and warranty dates (the existing
 * serial_tracking feature, nothing invented), purchases are spread over
 * the last ~2 months across real supplier records (credit ones land on
 * the supplier's payable), sales history covers cash/bKash/Nagad/credit
 * with sold phones linked to their exact serial via
 * sale_items.product_serial_id, and customers carry dues that are the
 * sum of their own credit invoices — so every figure an ambassador can
 * pull up actually reconciles.
 *
 * Same one-shot idempotent shape as SetupBabyPark/shop:setup-demo-clothing:
 * keyed by owner phone, every row firstOrCreate'd on a natural key, sales
 * guarded by wasRecentlyCreated so a re-run never duplicates line items.
 */
class SetupDemoMobileShop extends Command
{
    protected $signature = 'shop:setup-demo-mobile {--phone=01799000099} {--password=} {--dry-run} {--rollback}';

    protected $description = 'Idempotent "Mobile City Center" demo: serial/IMEI-tracked phones, suppliers, purchases, sales history, dues. --rollback removes it.';

    private const SHOP_NAME = 'Mobile City Center';

    private const FEATURE_KEYS = [
        'memo_whatsapp', 'memo_print', 'reports', 'expenses', 'accounts',
        'export', 'barcode_printing', 'purchases', 'returns', 'damages',
        'stock_count', 'activity_log', 'suppliers', 'low_stock_alerts',
        'cashier_management', 'serial_tracking', 'quotations',
    ];

    /** name, emoji — in nav order */
    private const CATEGORIES = [
        'স্মার্টফোন' => '📱', 'চার্জার ও ক্যাবল' => '🔌', 'অডিও' => '🎧',
        'পাওয়ার ব্যাংক' => '🔋', 'কভার ও প্রটেক্টর' => '🛡️',
        'মেমোরি ও স্টোরেজ' => '💾', 'স্মার্ট গ্যাজেট' => '⌚', 'অন্যান্য' => '📦',
    ];

    /** name, phone, address */
    private const SUPPLIERS = [
        ['Mobile Wholesale BD', '01811000001', 'গুলিস্তান, ঢাকা'],
        ['Dhaka Mobile Traders', '01811000002', 'মোতালেব প্লাজা, ঢাকা'],
        ['Chattogram Mobile Distribution', '01811000003', 'রিয়াজউদ্দিন বাজার, চট্টগ্রাম'],
        ['Smart Accessories Wholesale', '01811000004', 'নিউ মার্কেট, ঢাকা'],
        ['Apple & Samsung Distribution BD', '01811000005', 'বসুন্ধরা সিটি, ঢাকা'],
        ['Gadget Import House', '01811000006', 'আগ্রাবাদ, চট্টগ্রাম'],
    ];

    /**
     * Serial-tracked phones: name, price, cost, purchasedQty, soldQty,
     * supplier index, purchase-days-ago, IMEI seed (14 digits; per-unit
     * IMEIs increment from it and get a Luhn check digit appended).
     */
    private const PHONES = [
        ['Samsung Galaxy A06 4/64', 12499, 11400, 5, 2, 4, 52, '35401011100100'],
        ['Samsung Galaxy A16 6/128', 18999, 17300, 5, 2, 4, 45, '35401011100200'],
        ['Samsung Galaxy A26 8/128', 28999, 26500, 4, 1, 4, 38, '35401011100300'],
        ['Samsung Galaxy S24 FE', 84999, 79000, 2, 1, 4, 30, '35401011100400'],
        ['Redmi 13C 6/128', 14999, 13600, 6, 3, 0, 55, '86010011200100'],
        ['Redmi Note 13 8/256', 24999, 22800, 5, 2, 0, 41, '86010011200200'],
        ['Redmi A3 4/64', 10499, 9500, 6, 3, 0, 35, '86010011200300'],
        ['Realme C61 6/128', 15499, 14100, 4, 2, 1, 48, '86020022300100'],
        ['Realme Note 60 4/128', 12999, 11800, 4, 1, 1, 26, '86020022300200'],
        ['Vivo Y19s 6/128', 16999, 15500, 4, 2, 2, 44, '86030033400100'],
        ['Vivo Y29 8/128', 23999, 21900, 3, 1, 2, 22, '86030033400200'],
        ['Oppo A3x 4/64', 13999, 12700, 4, 2, 2, 50, '86040044500100'],
        ['Infinix Smart 9 4/128', 11499, 10400, 6, 3, 5, 47, '86050055600100'],
        ['Infinix Hot 50i 8/128', 14499, 13200, 5, 2, 5, 33, '86050055600200'],
        ['Tecno Spark 30C 8/128', 13499, 12300, 5, 2, 5, 28, '86060066700100'],
        ['iPhone 15 128GB', 94999, 89500, 2, 1, 4, 20, '35901099900100'],
    ];

    /** Quantity-based accessories: name, category, price, cost, stock, emoji */
    private const ACCESSORIES = [
        ['Samsung 25W Fast Charger (Original)', 'চার্জার ও ক্যাবল', 1450, 1150, 18, '🔌'],
        ['Xiaomi 33W Fast Charger', 'চার্জার ও ক্যাবল', 1150, 900, 14, '🔌'],
        ['Baseus 20W Dual Port Charger', 'চার্জার ও ক্যাবল', 850, 640, 22, '🔌'],
        ['USB Type-C Cable 1m (Baseus)', 'চার্জার ও ক্যাবল', 350, 230, 40, '🔌'],
        ['USB Type-C Cable 2m (UGREEN)', 'চার্জার ও ক্যাবল', 550, 400, 25, '🔌'],
        ['Micro USB Cable 1m', 'চার্জার ও ক্যাবল', 150, 90, 60, '🔌'],
        ['Lightning Cable (Apple Certified)', 'চার্জার ও ক্যাবল', 1250, 980, 8, '🔌'],
        ['OTG Connector Type-C', 'চার্জার ও ক্যাবল', 120, 70, 35, '🔌'],
        ['4-Port USB Hub', 'চার্জার ও ক্যাবল', 650, 480, 6, '🔌'],
        ['Car Charger Dual USB', 'চার্জার ও ক্যাবল', 450, 320, 0, '🔌'],
        ['Realme Buds T110 TWS', 'অডিও', 1890, 1550, 12, '🎧'],
        ['Xiaomi Redmi Buds 4 Lite', 'অডিও', 2250, 1880, 9, '🎧'],
        ['JBL Wave 200 TWS', 'অডিও', 4500, 3900, 4, '🎧'],
        ['Realme Buds Wireless 3 Neckband', 'অডিও', 2450, 2050, 7, '🎧'],
        ['Lenovo HE05 Neckband', 'অডিও', 850, 620, 16, '🎧'],
        ['Wired Earphone (Samsung Original)', 'অডিও', 450, 310, 30, '🎧'],
        ['Wired Earphone (Generic)', 'অডিও', 120, 60, 80, '🎧'],
        ['P47 Bluetooth Headphone', 'অডিও', 750, 520, 11, '🎧'],
        ['Mini Bluetooth Speaker', 'অডিও', 950, 700, 5, '🔊'],
        ['JBL Go 3 Speaker', 'অডিও', 4200, 3650, 3, '🔊'],
        ['Xiaomi Power Bank 10000mAh', 'পাওয়ার ব্যাংক', 1850, 1520, 10, '🔋'],
        ['Baseus Power Bank 20000mAh', 'পাওয়ার ব্যাংক', 2950, 2480, 6, '🔋'],
        ['Remax Power Bank 10000mAh', 'পাওয়ার ব্যাংক', 1350, 1050, 0, '🔋'],
        ['Samsung A16 Silicone Cover', 'কভার ও প্রটেক্টর', 250, 140, 45, '🛡️'],
        ['Redmi Note 13 Magnetic Cover', 'কভার ও প্রটেক্টর', 350, 210, 28, '🛡️'],
        ['iPhone 15 Clear Case', 'কভার ও প্রটেক্টর', 550, 360, 12, '🛡️'],
        ['Tempered Glass 9H (Universal)', 'কভার ও প্রটেক্টর', 150, 70, 100, '🛡️'],
        ['UV Curved Glass Protector', 'কভার ও প্রটেক্টর', 450, 280, 20, '🛡️'],
        ['Camera Lens Protector', 'কভার ও প্রটেক্টর', 120, 60, 2, '🛡️'],
        ['Samsung EVO Plus 64GB MicroSD', 'মেমোরি ও স্টোরেজ', 850, 690, 15, '💾'],
        ['Samsung EVO Plus 128GB MicroSD', 'মেমোরি ও স্টোরেজ', 1450, 1220, 10, '💾'],
        ['SanDisk 64GB Pendrive', 'মেমোরি ও স্টোরেজ', 750, 600, 14, '💾'],
        ['SanDisk 32GB Pendrive', 'মেমোরি ও স্টোরেজ', 550, 430, 3, '💾'],
        ['Xiaomi Mi Band 9', 'স্মার্ট গ্যাজেট', 4250, 3700, 6, '⌚'],
        ['Amazfit Bip 5 Smart Watch', 'স্মার্ট গ্যাজেট', 7500, 6600, 3, '⌚'],
        ['T500 Smart Watch', 'স্মার্ট গ্যাজেট', 1250, 950, 18, '⌚'],
        ['Mobile Holder (Bike)', 'অন্যান্য', 450, 300, 9, '📦'],
        ['Mobile Holder (Desk)', 'অন্যান্য', 350, 220, 13, '📦'],
        ['Tripod 3110 with Holder', 'অন্যান্য', 650, 450, 7, '📦'],
        ['Selfie Stick with Remote', 'অন্যান্য', 550, 380, 0, '📦'],
    ];

    /** name, phone — dues/spent/visits are computed from the sales below */
    private const CUSTOMERS = [
        ['রাকিব হাসান', '01712100001'], ['সুমাইয়া আক্তার', '01812100002'],
        ['তানভীর আহমেদ', '01912100003'], ['মো. জসিম উদ্দিন', '01612100004'],
        ['ফারহানা ইয়াসমিন', '01512100005'], ['আরিফুল ইসলাম', '01712100006'],
        ['নুসরাত জাহান', '01812100007'], ['কামরুল হাসান', '01912100008'],
        ['শারমিন সুলতানা', '01612100009'], ['মেহেদী হাসান', '01712100010'],
        ['আয়েশা সিদ্দিকা', '01812100011'], ['রফিকুল ইসলাম', '01912100012'],
        ['জান্নাতুল ফেরদৌস', '01612100013'], ['সাকিব খান', '01712100014'],
        ['তাসনিম রহমান', '01812100015'], ['ইমরান হোসেন', '01912100016'],
    ];

    /** Accessory sales: [daysAgo, payment_mode, customer index (null = walk-in), [[accessory index, qty], ...]] */
    private const ACCESSORY_SALES = [
        [44, 'cash', null, [[3, 2], [26, 3]]],
        [41, 'bkash', 0, [[10, 1]]],
        [39, 'cash', null, [[5, 4]]],
        [36, 'nagad', 1, [[20, 1], [3, 1]]],
        [33, 'credit', 2, [[12, 1]]],
        [30, 'cash', null, [[23, 2], [26, 2]]],
        [27, 'bkash', 3, [[33, 1]]],
        [24, 'cash', 4, [[0, 1], [26, 1]]],
        [21, 'credit', 5, [[21, 1], [4, 1]]],
        [18, 'nagad', null, [[15, 2]]],
        [15, 'cash', 6, [[30, 1], [26, 2]]],
        [12, 'bkash', 7, [[11, 1]]],
        [9, 'cash', null, [[16, 5], [5, 2]]],
        [6, 'credit', 8, [[34, 1]]],
        [3, 'cash', 9, [[1, 1], [3, 1], [26, 1]]],
        [1, 'nagad', null, [[36, 1], [26, 1]]],
    ];

    public function handle(): int
    {
        $phone = trim((string) $this->option('phone'));

        if ($this->option('rollback')) {
            return $this->rollback($phone);
        }

        $existing = Shop::withoutGlobalScopes()->where('phone', $phone)->first();
        $phoneCount = count(self::PHONES);
        $serialCount = array_sum(array_column(self::PHONES, 3));

        $this->info('--- '.self::SHOP_NAME.' সেটআপ ---');
        $this->line('Shop: '.($existing ? "আগেই আছে (ID {$existing->id})" : "নতুন তৈরি হবে (ফোন: {$phone})"));
        $this->line("{$phoneCount}টা ফোন মডেল (IMEI/সিরিয়াল-ট্র্যাকড, মোট {$serialCount} ইউনিট) + ".count(self::ACCESSORIES).'টা এক্সেসরিজ');
        $this->line(count(self::SUPPLIERS).' সাপ্লায়ার · '.count(self::CUSTOMERS).' কাস্টমার · কেনা-বেচার হিস্ট্রি গত ~২ মাস জুড়ে');

        if ($this->option('dry-run')) {
            $this->newLine();
            $this->info('এটা শুধু প্রিভিউ — কিছু লেখা হয়নি।');

            return self::SUCCESS;
        }

        $password = $this->option('password') ?: Str::password(10);
        $passwordWasGenerated = ! $this->option('password');
        $shopWasCreated = ! $existing;

        $shop = DB::transaction(function () use ($existing, $phone, $password) {
            (new FeatureSeeder)->run();
            BusinessType::updateOrCreate(
                ['slug' => 'mobile'],
                array_merge(config('business_types.mobile', ['label_bn' => 'মোবাইল শপ', 'label_en' => 'Mobile Shop']), ['is_active' => true])
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
                        'name_en' => self::SHOP_NAME,
                        'phone' => $phone,
                        'area' => 'ঢাকা',
                        'owner_name' => 'ডেমো ওনার',
                        'business_type_id' => BusinessType::where('slug', 'mobile')->value('id'),
                        'sales_mode' => 'both',
                        'lang' => 'bn',
                        'plan' => 'monthly',
                        'account_type' => 'demo',
                        'status' => 'active',
                        'staff_limit' => 10,
                        'subscription_start' => now()->toDateString(),
                        'subscription_expiry' => now()->addYear()->toDateString(),
                        'cash_balance' => 45000, 'bank_balance' => 120000, 'capital' => 500000,
                        'vat_mode' => 'none', 'invoice_counter' => 2000,
                        'onboarded_at' => now(),
                    ],
                    'ডেমো ওনার', $phone, null, $password, self::FEATURE_KEYS
                );
                ProductCategory::withoutGlobalScopes()->where('shop_id', $shop->id)->delete();
            }

            $this->seedEverything($shop);

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

    private function seedEverything(Shop $shop): void
    {
        $pieceUnitId = Unit::withoutGlobalScopes()->where('shop_id', $shop->id)
            ->where('name', 'like', '%পিস%')->value('id');

        $cats = [];
        foreach (self::CATEGORIES as $name => $emoji) {
            $cats[$name] = ProductCategory::withoutGlobalScopes()->updateOrCreate(
                ['shop_id' => $shop->id, 'name' => $name], ['emoji' => $emoji]
            );
        }

        $suppliers = [];
        foreach (self::SUPPLIERS as [$name, $sPhone, $address]) {
            $suppliers[] = Supplier::withoutGlobalScopes()->updateOrCreate(
                ['shop_id' => $shop->id, 'name' => $name],
                ['phone' => $sPhone, 'address' => $address]
            );
        }

        $customers = [];
        foreach (self::CUSTOMERS as [$name, $cPhone]) {
            $customers[] = Customer::withoutGlobalScopes()->firstOrCreate(
                ['shop_id' => $shop->id, 'phone' => $cPhone],
                ['name' => $name, 'due' => 0, 'total_spent' => 0, 'visits' => 0]
            );
        }

        // ---- phones: product + purchase + per-unit serials ----
        $phoneModels = [];
        $supplierPayable = array_fill(0, count($suppliers), 0.0);
        foreach (self::PHONES as $pi => [$name, $price, $cost, $bought, $sold, $supIdx, $daysAgo, $imeiSeed]) {
            $product = Product::withoutGlobalScopes()->updateOrCreate(
                ['shop_id' => $shop->id, 'name' => $name],
                [
                    'category_id' => $cats['স্মার্টফোন']->id,
                    'unit_id' => $pieceUnitId,
                    'emoji' => '📱',
                    'barcode' => 'MCC-PH-'.str_pad((string) ($pi + 1), 3, '0', STR_PAD_LEFT),
                    'cost' => $cost, 'price' => $price,
                    'stock' => $bought - $sold,
                    'deleted_at' => null,
                ]
            );
            $phoneModels[$pi] = $product;

            $purchaseDate = now()->subDays($daysAgo)->toDateString();
            // every 3rd phone batch bought on credit -> lands on the
            // supplier's payable, so supplier dues have real history too
            $method = $pi % 3 === 2 ? 'credit' : ($pi % 2 === 0 ? 'cash' : 'bank');
            $amount = $cost * $bought;
            if ($method === 'credit') {
                $supplierPayable[$supIdx] += $amount;
            }
            Purchase::withoutGlobalScopes()->firstOrCreate(
                ['shop_id' => $shop->id, 'memo' => 'PINV-'.str_pad((string) (101 + $pi), 4, '0', STR_PAD_LEFT)],
                [
                    'supplier' => $suppliers[$supIdx]->name,
                    'supplier_id' => $suppliers[$supIdx]->id,
                    'product_id' => $product->id,
                    'qty' => $bought,
                    'amount' => $amount,
                    'method' => $method,
                    'date' => $purchaseDate,
                ]
            );

            // per-unit serials: Luhn-valid IMEIs, 12-month warranty from the
            // purchase date; the first $sold units are the ones the sales
            // below link to by product_serial_id
            for ($u = 0; $u < $bought; $u++) {
                ProductSerial::withoutGlobalScopes()->updateOrCreate(
                    ['shop_id' => $shop->id, 'imei' => self::imei($imeiSeed, $u)],
                    [
                        'product_id' => $product->id,
                        'cost' => $cost,
                        'warranty_expiry' => now()->subDays($daysAgo)->addYear()->toDateString(),
                        'status' => $u < $sold ? 'sold' : 'in_stock',
                    ]
                );
            }
        }

        // ---- accessory products + a few bulk accessory purchases ----
        $accModels = [];
        foreach (self::ACCESSORIES as $ai => [$name, $catName, $price, $cost, $stock, $emoji]) {
            $accModels[$ai] = Product::withoutGlobalScopes()->updateOrCreate(
                ['shop_id' => $shop->id, 'name' => $name],
                [
                    'category_id' => $cats[$catName]->id,
                    'unit_id' => $pieceUnitId,
                    'emoji' => $emoji,
                    'barcode' => 'MCC-AC-'.str_pad((string) ($ai + 1), 3, '0', STR_PAD_LEFT),
                    'cost' => $cost, 'price' => $price, 'stock' => $stock,
                    'deleted_at' => null,
                ]
            );
        }
        foreach ([[3, 58, 28500, 'cash'], [3, 40, 19800, 'credit'], [5, 25, 32400, 'bank'], [3, 12, 15600, 'cash']] as $bi => [$supIdx, $days, $amount, $method]) {
            if ($method === 'credit') {
                $supplierPayable[$supIdx] += $amount;
            }
            Purchase::withoutGlobalScopes()->firstOrCreate(
                ['shop_id' => $shop->id, 'memo' => 'PINV-'.str_pad((string) (201 + $bi), 4, '0', STR_PAD_LEFT)],
                [
                    'supplier' => $suppliers[$supIdx]->name,
                    'supplier_id' => $suppliers[$supIdx]->id,
                    'amount' => $amount,
                    'method' => $method,
                    'date' => now()->subDays($days)->toDateString(),
                ]
            );
        }

        foreach ($suppliers as $si => $supplier) {
            $supplier->update(['payable' => $supplierPayable[$si]]);
        }

        // ---- sales: every sold phone unit gets its own invoice, linked to
        // its serial; accessory sales follow the fixed plan above ----
        $invoice = 2001;
        $customerTotals = [];
        $modes = ['cash', 'bkash', 'nagad', 'credit', 'cash', 'bkash'];

        foreach (self::PHONES as $pi => [$name, $price, $cost, $bought, $sold, $supIdx, $daysAgo, $imeiSeed]) {
            for ($u = 0; $u < $sold; $u++) {
                $custIdx = ($pi + $u) % count($customers);
                $mode = $modes[($pi + $u) % count($modes)];
                $saleDaysAgo = max(0, $daysAgo - 4 - $u * 6);
                $this->createSale($shop, 'INV-'.$invoice++, $saleDaysAgo, $mode, $customers[$custIdx], [
                    ['product' => $phoneModels[$pi], 'qty' => 1, 'serial_imei' => self::imei($imeiSeed, $u)],
                ], $customerTotals, $custIdx);
            }
        }

        foreach (self::ACCESSORY_SALES as [$daysAgo, $mode, $custIdx, $items]) {
            $lines = array_map(fn ($it) => ['product' => $accModels[$it[0]], 'qty' => $it[1], 'serial_imei' => null], $items);
            $this->createSale($shop, 'INV-'.$invoice++, $daysAgo, $mode, $custIdx !== null ? $customers[$custIdx] : null, $lines, $customerTotals, $custIdx);
        }

        // dues/spent/visits are the exact sums of each customer's own
        // invoices above — the figures an ambassador opens must reconcile
        foreach ($customerTotals as $ci => $t) {
            $customers[$ci]->update(['due' => $t['due'], 'total_spent' => $t['spent'], 'visits' => $t['visits']]);
        }

        Shop::withoutGlobalScopes()->whereKey($shop->id)->update(['invoice_counter' => max($shop->invoice_counter, $invoice)]);

        $rent = ExpenseCategory::withoutGlobalScopes()->firstOrCreate(['shop_id' => $shop->id, 'name' => 'দোকান ভাড়া'], ['emoji' => '🏠']);
        $bill = ExpenseCategory::withoutGlobalScopes()->firstOrCreate(['shop_id' => $shop->id, 'name' => 'বিদ্যুৎ বিল'], ['emoji' => '💡']);
        Expense::withoutGlobalScopes()->firstOrCreate(['shop_id' => $shop->id, 'expense_category_id' => $rent->id, 'memo' => 'মাসিক দোকান ভাড়া'], ['amount' => 25000, 'method' => 'cash', 'date' => now()->subDays(12)->toDateString()]);
        Expense::withoutGlobalScopes()->firstOrCreate(['shop_id' => $shop->id, 'expense_category_id' => $bill->id, 'memo' => 'বিদ্যুৎ বিল'], ['amount' => 3200, 'method' => 'cash', 'date' => now()->subDays(8)->toDateString()]);
    }

    /** @param  array<int, array{product: Product, qty: int, serial_imei: ?string}>  $lines */
    private function createSale(Shop $shop, string $invoiceNo, int $daysAgo, string $mode, ?Customer $customer, array $lines, array &$customerTotals, ?int $custIdx): void
    {
        $subtotal = 0;
        $profit = 0;
        foreach ($lines as $l) {
            $subtotal += $l['product']->price * $l['qty'];
            $profit += ($l['product']->price - $l['product']->cost) * $l['qty'];
        }

        $sale = Sale::withoutGlobalScopes()->firstOrCreate(
            ['shop_id' => $shop->id, 'invoice_no' => $invoiceNo],
            [
                'customer_id' => $customer?->id,
                'date' => now()->subDays($daysAgo)->toDateString(),
                'time' => sprintf('%02d:%02d:00', 10 + ($daysAgo % 9), ($daysAgo * 7) % 60),
                'subtotal' => $subtotal, 'discount' => 0, 'vat' => 0,
                'total' => $subtotal, 'profit' => $profit,
                'payment_mode' => $mode,
            ]
        );

        if ($custIdx !== null) {
            $customerTotals[$custIdx] ??= ['due' => 0, 'spent' => 0, 'visits' => 0];
            $customerTotals[$custIdx]['spent'] += $subtotal;
            $customerTotals[$custIdx]['visits']++;
            if ($mode === 'credit') {
                $customerTotals[$custIdx]['due'] += $subtotal;
            }
        }

        if (! $sale->wasRecentlyCreated) {
            return;
        }

        foreach ($lines as $l) {
            $serialId = $l['serial_imei']
                ? ProductSerial::withoutGlobalScopes()->where('shop_id', $shop->id)->where('imei', $l['serial_imei'])->value('id')
                : null;
            SaleItem::create([
                'shop_id' => $shop->id,
                'sale_id' => $sale->id,
                'product_id' => $l['product']->id,
                'product_serial_id' => $serialId,
                'product_name' => $l['product']->name,
                'qty' => $l['qty'],
                'price' => $l['product']->price,
                'cost' => $l['product']->cost,
            ]);
        }
    }

    /** 15-digit Luhn-valid IMEI: 14-digit seed + unit offset, check digit appended. */
    public static function imei(string $seed14, int $offset): string
    {
        $body = str_pad((string) (((int) $seed14) + $offset), 14, '0', STR_PAD_LEFT);
        $sum = 0;
        foreach (str_split($body) as $i => $ch) {
            $d = (int) $ch;
            if ($i % 2 === 1) {
                $d *= 2;
                if ($d > 9) {
                    $d -= 9;
                }
            }
            $sum += $d;
        }

        return $body.((10 - ($sum % 10)) % 10);
    }

    private function rollback(string $phone): int
    {
        $shop = Shop::withoutGlobalScopes()->where('phone', $phone)->first();
        if (! $shop) {
            $this->info('কোনো Mobile City Center ডেমো পাওয়া যায়নি — করার কিছু নেই।');

            return self::SUCCESS;
        }

        DB::transaction(function () use ($shop) {
            SaleItem::withoutGlobalScopes()->where('shop_id', $shop->id)->delete();
            Sale::withoutGlobalScopes()->where('shop_id', $shop->id)->delete();
            ProductSerial::withoutGlobalScopes()->where('shop_id', $shop->id)->delete();
            Purchase::withoutGlobalScopes()->where('shop_id', $shop->id)->delete();
            Expense::withoutGlobalScopes()->where('shop_id', $shop->id)->delete();
            ExpenseCategory::withoutGlobalScopes()->where('shop_id', $shop->id)->delete();
            Customer::withoutGlobalScopes()->where('shop_id', $shop->id)->forceDelete();
            Supplier::withoutGlobalScopes()->where('shop_id', $shop->id)->forceDelete();
            Product::withoutGlobalScopes()->where('shop_id', $shop->id)->forceDelete();
            ProductCategory::withoutGlobalScopes()->where('shop_id', $shop->id)->delete();
            Unit::withoutGlobalScopes()->where('shop_id', $shop->id)->delete();
            User::where('shop_id', $shop->id)->delete();
            $shop->features()->detach();
            $shop->delete();
        });

        $this->info('✅ Mobile City Center ডেমো মুছে ফেলা হয়েছে।');

        return self::SUCCESS;
    }
}
