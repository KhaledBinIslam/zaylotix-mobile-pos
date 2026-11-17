<?php

namespace App\Console\Commands;

use App\Models\Attendance;
use App\Models\BusinessType;
use App\Models\Customer;
use App\Models\Employee;
use App\Models\Expense;
use App\Models\ExpenseCategory;
use App\Models\Feature;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\ProductSerial;
use App\Models\Purchase;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\SalaryAdvance;
use App\Models\SalaryPayment;
use App\Models\ServiceTicket;
use App\Models\ServiceTicketItem;
use App\Models\Shop;
use App\Models\StockTransfer;
use App\Models\Supplier;
use App\Models\Unit;
use App\Models\User;
use App\Support\Payroll;
use App\Support\ShopProvisioner;
use Database\Seeders\FeatureSeeder;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * "Global Touch" — demo electronics/mobile/CCTV shop for a prospective
 * client pitch, built by extending the SAME pattern as
 * shop:setup-demo-mobile ("Mobile City Center") rather than inventing a
 * parallel system: the 'mobile' business type (already literally labeled
 * "Mobile/Electronics Shop"), the existing serial_tracking feature +
 * ProductSerial for IMEI/serial-tracked units (phones, CCTV DVRs,
 * laptops), the existing Purchase/Supplier module, and the existing
 * ServiceTicket module for warranty/repair. CCTV "installation projects"
 * were audited and genuinely do not exist anywhere in this codebase —
 * that's out of scope here and was not invented; a CCTV sale is
 * represented as an ordinary sale (camera + DVR + cable), same as any
 * other product, with a ServiceTicket demonstrating the warranty/repair
 * side instead.
 *
 * Also seeds one branch (Shop.parent_shop_id, the existing generic
 * branch mechanism — see SetupBabyPark.php for the same snippet) and a
 * couple of Employees + Attendance + one payroll run, since HR/Payroll
 * is a real, separate, already-working module worth showing in the demo.
 *
 * Same one-shot idempotent shape as the other Setup*Shop commands: keyed
 * by owner phone, every row firstOrCreate'd/updateOrCreate'd on a
 * natural key, sales guarded by wasRecentlyCreated.
 */
class SetupGlobalTouch extends Command
{
    protected $signature = 'shop:setup-global-touch {--phone=01799000199} {--password=} {--dry-run} {--rollback}';

    protected $description = 'Idempotent "Global Touch" demo: electronics/mobile/CCTV shop with IMEI-tracked phones+CCTV+laptops, suppliers, purchases, sales history, employees/payroll, one branch, warranty service ticket. --rollback removes it.';

    private const SHOP_NAME = 'Global Touch';

    private const FEATURE_KEYS = [
        'memo_whatsapp', 'memo_print', 'reports', 'expenses', 'accounts',
        'export', 'barcode_printing', 'purchases', 'returns', 'damages',
        'stock_count', 'activity_log', 'suppliers', 'low_stock_alerts',
        'cashier_management', 'serial_tracking', 'quotations',
        // without these two, Employees/Payroll and ServiceTickets have real
        // seeded data but never show up in the nav at all (useNavItems.js
        // gates them on hasFeature('hr_payroll')/hasFeature('service_billing')
        // specifically, separate from 'accounts'/'purchases' etc.) — a real
        // gap found only by actually checking the demo, not assuming the
        // other feature keys covered it.
        'hr_payroll', 'service_billing', 'purchase_orders',
    ];

    /** name, emoji — in nav order */
    private const CATEGORIES = [
        'স্মার্টফোন' => '📱', 'ল্যাপটপ ও কম্পিউটার' => '💻', 'সিসিটিভি ক্যামেরা' => '📹',
        'ডিভিআর/এনভিআর' => '🖥️', 'নেটওয়ার্কিং' => '🌐', 'প্রিন্টার' => '🖨️',
        'পস ইকুইপমেন্ট' => '🧾', 'চার্জার ও ক্যাবল' => '🔌', 'অডিও' => '🎧',
        'পাওয়ার ব্যাংক' => '🔋', 'কভার ও প্রটেক্টর' => '🛡️', 'মেমোরি ও স্টোরেজ' => '💾',
        'সিকিউরিটি এক্সেসরিজ' => '🔒', 'অন্যান্য' => '📦',
    ];

    /** name, phone, address */
    private const SUPPLIERS = [
        ['Global Electronics Distribution', '01822000001', 'আইডিবি ভবন, ঢাকা'],
        ['Dhaka CCTV Wholesale', '01822000002', 'নয়াপল্টন, ঢাকা'],
        ['Smart Networking BD', '01822000003', 'বিজয়নগর, ঢাকা'],
        ['Apple & Samsung Distribution BD', '01822000004', 'বসুন্ধরা সিটি, ঢাকা'],
        ['Laptop Zone Wholesale', '01822000005', 'মোতালেব প্লাজা, ঢাকা'],
        ['Chattogram Electronics Hub', '01822000006', 'আগ্রাবাদ, চট্টগ্রাম'],
    ];

    /**
     * Serial-tracked units (phones, CCTV DVR/NVR kits, laptops): name,
     * category, price, cost, purchasedQty, soldQty, supplier index,
     * purchase-days-ago, serial seed. Phones get a real Luhn-valid IMEI
     * (same generator as Mobile City Center); non-phone categories get a
     * plain serial string — ProductSerial.imei is just a string column,
     * not IMEI-format-validated, so this is a faithful reuse, not a hack.
     */
    private const SERIAL_PRODUCTS = [
        ['Samsung Galaxy A16 6/128', 'স্মার্টফোন', 18999, 17300, 16, 11, 3, 172, 'imei:86010011300100'],
        ['Samsung Galaxy A26 8/128', 'স্মার্টফোন', 28999, 26500, 12, 7, 3, 168, 'imei:86010011300200'],
        ['Samsung Galaxy S24 FE', 'স্মার্টফোন', 84999, 79000, 8, 5, 3, 160, 'imei:86010011300300'],
        ['iPhone 15 128GB', 'স্মার্টফোন', 94999, 89500, 6, 4, 3, 150, 'imei:35901099910100'],
        ['Redmi Note 13 8/256', 'স্মার্টফোন', 24999, 22800, 14, 9, 0, 165, 'imei:86020022400100'],
        ['Dell Vostro 3520 i5', 'ল্যাপটপ ও কম্পিউটার', 68500, 61000, 9, 6, 4, 155, 'serial:DELLVOS-2026-001'],
        ['HP 15 Core i3 12th Gen', 'ল্যাপটপ ও কম্পিউটার', 52500, 46500, 11, 7, 4, 145, 'serial:HP15-2026-001'],
        ['Lenovo IdeaPad Slim 3', 'ল্যাপটপ ও কম্পিউটার', 58900, 52000, 8, 5, 4, 135, 'serial:LEN-IDPD-2026-001'],
        ['Hikvision 4CH DVR Kit (2MP x4)', 'ডিভিআর/এনভিআর', 18500, 15800, 13, 8, 1, 162, 'serial:HIKDVR4CH-2026-001'],
        ['Hikvision 8CH NVR Kit (4MP x8)', 'ডিভিআর/এনভিআর', 42500, 37000, 9, 5, 1, 148, 'serial:HIKNVR8CH-2026-001'],
        ['Dahua 4CH DVR Kit (2MP x4)', 'ডিভিআর/এনভিআর', 16900, 14200, 11, 7, 1, 140, 'serial:DAHDVR4CH-2026-001'],
    ];

    /** Quantity-based accessories: name, category, price, cost, stock, emoji */
    private const ACCESSORIES = [
        ['Hikvision 2MP Dome Camera', 'সিসিটিভি ক্যামেরা', 2450, 1950, 24, '📹'],
        ['Hikvision 2MP Bullet Camera', 'সিসিটিভি ক্যামেরা', 2650, 2100, 20, '📹'],
        ['Dahua 4MP Bullet Camera', 'সিসিটিভি ক্যামেরা', 3850, 3150, 12, '📹'],
        ['CP Plus 2MP Dome Camera', 'সিসিটিভি ক্যামেরা', 1950, 1500, 16, '📹'],
        ['CCTV Cable 3+1 (90m roll)', 'নেটওয়ার্কিং', 2200, 1750, 10, '🌐'],
        ['CAT6 Networking Cable (90m roll)', 'নেটওয়ার্কিং', 2850, 2300, 8, '🌐'],
        ['8-Port Network Switch', 'নেটওয়ার্কিং', 1450, 1100, 9, '🌐'],
        ['24-Port Network Switch', 'নেটওয়ার্কিং', 6200, 5300, 3, '🌐'],
        ['WiFi Router Dual Band', 'নেটওয়ার্কিং', 2450, 1950, 11, '🌐'],
        ['2TB Surveillance HDD (Seagate)', 'ডিভিআর/এনভিআর', 7850, 6900, 10, '🖥️'],
        ['1TB Surveillance HDD (WD Purple)', 'ডিভিআর/এনভিআর', 4650, 4000, 14, '🖥️'],
        ['Canon LBP2900B Printer', 'প্রিন্টার', 12500, 10800, 5, '🖨️'],
        ['Epson L3250 All-in-One Printer', 'প্রিন্টার', 19500, 17200, 4, '🖨️'],
        ['Thermal POS Receipt Printer 80mm', 'পস ইকুইপমেন্ট', 6500, 5400, 6, '🧾'],
        ['Barcode Scanner (USB)', 'পস ইকুইপমেন্ট', 2850, 2300, 8, '🧾'],
        ['Cash Drawer (POS)', 'পস ইকুইপমেন্ট', 5500, 4600, 4, '🧾'],
        ['Samsung 25W Fast Charger (Original)', 'চার্জার ও ক্যাবল', 1450, 1150, 16, '🔌'],
        ['USB Type-C Cable 1m (Baseus)', 'চার্জার ও ক্যাবল', 350, 230, 35, '🔌'],
        ['Power Bank 10000mAh (Xiaomi)', 'পাওয়ার ব্যাংক', 1850, 1520, 10, '🔋'],
        ['Laptop Bag (14 inch)', 'কভার ও প্রটেক্টর', 950, 650, 14, '🛡️'],
        ['Tempered Glass 9H (Universal)', 'কভার ও প্রটেক্টর', 150, 70, 60, '🛡️'],
        ['Samsung EVO Plus 128GB MicroSD', 'মেমোরি ও স্টোরেজ', 1450, 1220, 12, '💾'],
        ['SanDisk 64GB Pendrive', 'মেমোরি ও স্টোরেজ', 750, 600, 14, '💾'],
        ['Door Access Control (Biometric)', 'সিকিউরিটি এক্সেসরিজ', 8500, 7200, 4, '🔒'],
        ['Magnetic Door Lock', 'সিকিউরিটি এক্সেসরিজ', 2250, 1800, 7, '🔒'],
        ['Mobile Holder (Desk)', 'অন্যান্য', 350, 220, 13, '📦'],
    ];

    /** name, phone — dues/spent/visits are computed from the sales below */
    private const CUSTOMERS = [
        ['রাকিব হাসান', '01712200001'], ['সুমাইয়া আক্তার', '01812200002'],
        ['তানভীর আহমেদ', '01912200003'], ['মো. জসিম উদ্দিন', '01612200004'],
        ['ফারহানা ইয়াসমিন', '01512200005'], ['আরিফুল ইসলাম', '01712200006'],
        ['নুসরাত জাহান', '01812200007'], ['কামরুল হাসান', '01912200008'],
        ['শারমিন সুলতানা', '01612200009'], ['মেহেদী হাসান', '01712200010'],
        ['আয়েশা সিদ্দিকা', '01812200011'], ['রফিকুল ইসলাম', '01912200012'],
    ];

    /** name, phone, designation, salary_type, basic_salary, joining_days_ago */
    private const EMPLOYEES = [
        ['মো. সাজ্জাদ হোসেন', '01911300001', 'সেলস এক্সিকিউটিভ', 'monthly', 18000, 180],
        ['তৌহিদুল ইসলাম', '01911300002', 'টেকনিশিয়ান', 'monthly', 22000, 120],
    ];

    /**
     * Generates ~180 days of accessory sales (3-4 a week, deterministic —
     * no real randomness so --dry-run/re-run stays predictable) instead of
     * a short hand-written list, so the Business Dashboard's 6-month
     * revenue trend and Branch Leaderboard actually have 6 real months of
     * variation to show (Khaled's explicit ask: "6 mas er dummy data dio
     * jate boji ki korche" — a ~50-day-deep demo couldn't show that).
     * Returns the same shape the old hand-written ACCESSORY_SALES had:
     * [daysAgo, payment_mode, customer index (null = walk-in), [[accessory index, qty], ...]].
     */
    private static function accessorySales(int $accessoryCount, int $customerCount): array
    {
        $modes = ['cash', 'cash', 'bkash', 'nagad', 'credit'];
        $sales = [];
        for ($day = 178; $day >= 1; $day -= 2) {
            $seed = $day * 7;
            $lineCount = 1 + ($seed % 2);
            $items = [];
            for ($l = 0; $l < $lineCount; $l++) {
                $items[] = [($seed + $l * 11) % $accessoryCount, 1 + ($seed % 3)];
            }
            $custIdx = ($seed % 4 === 0) ? null : ($seed % $customerCount);
            $sales[] = [$day, $modes[$seed % count($modes)], $custIdx, $items];
        }

        return $sales;
    }

    public function handle(): int
    {
        $phone = trim((string) $this->option('phone'));

        if ($this->option('rollback')) {
            return $this->rollback($phone);
        }

        $existing = Shop::withoutGlobalScopes()->where('phone', $phone)->first();
        $serialModelCount = count(self::SERIAL_PRODUCTS);
        $serialUnitCount = array_sum(array_column(self::SERIAL_PRODUCTS, 4));

        $this->info('--- '.self::SHOP_NAME.' সেটআপ ---');
        $this->line('Shop: '.($existing ? "আগেই আছে (ID {$existing->id})" : "নতুন তৈরি হবে (ফোন: {$phone})"));
        $this->line("{$serialModelCount}টা সিরিয়াল/IMEI-ট্র্যাকড মডেল (মোট {$serialUnitCount} ইউনিট) + ".count(self::ACCESSORIES).'টা এক্সেসরিজ/ইকুইপমেন্ট');
        $this->line(count(self::SUPPLIERS).' সাপ্লায়ার · '.count(self::CUSTOMERS).' কাস্টমার · '.count(self::EMPLOYEES).' স্টাফ · ১ শাখা + ১ ওয়্যারহাউজ (নিজস্ব স্টক/বিক্রি-সহ) · ওয়্যারহাউজ→শাখা ও শাখা→শাখা ট্রান্সফার · কেনা-বেচার হিস্ট্রি গত ~৬ মাস জুড়ে');

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
                        'cash_balance' => 60000, 'bank_balance' => 180000, 'capital' => 800000,
                        'vat_mode' => 'none', 'invoice_counter' => 3000,
                        'onboarded_at' => now(),
                    ],
                    'ডেমো ওনার', $phone, null, $password, self::FEATURE_KEYS
                );
                ProductCategory::withoutGlobalScopes()->where('shop_id', $shop->id)->delete();
            }

            $this->seedEverything($shop);
            $this->seedBranch($shop);

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

        // ---- serial-tracked units: product + purchase + per-unit serials ----
        $serialModels = [];
        $supplierPayable = array_fill(0, count($suppliers), 0.0);
        $firstServiceTicketSerial = null;
        foreach (self::SERIAL_PRODUCTS as $pi => [$name, $catName, $price, $cost, $bought, $sold, $supIdx, $daysAgo, $serialSeed]) {
            $product = Product::withoutGlobalScopes()->updateOrCreate(
                ['shop_id' => $shop->id, 'name' => $name],
                [
                    'category_id' => $cats[$catName]->id,
                    'unit_id' => $pieceUnitId,
                    'emoji' => $cats[$catName]->emoji,
                    'barcode' => 'GT-SN-'.str_pad((string) ($pi + 1), 3, '0', STR_PAD_LEFT),
                    'cost' => $cost, 'price' => $price,
                    'stock' => $bought - $sold,
                    'deleted_at' => null,
                ]
            );
            $serialModels[$pi] = $product;

            $purchaseDate = now()->subDays($daysAgo)->toDateString();
            $method = $pi % 3 === 2 ? 'credit' : ($pi % 2 === 0 ? 'cash' : 'bank');
            $amount = $cost * $bought;
            if ($method === 'credit') {
                $supplierPayable[$supIdx] += $amount;
            }
            Purchase::withoutGlobalScopes()->firstOrCreate(
                ['shop_id' => $shop->id, 'memo' => 'PINV-'.str_pad((string) (301 + $pi), 4, '0', STR_PAD_LEFT)],
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

            [$kind, $seed] = explode(':', $serialSeed, 2);
            for ($u = 0; $u < $bought; $u++) {
                $serialValue = $kind === 'imei' ? self::imei($seed, $u) : $seed.'-'.str_pad((string) ($u + 1), 2, '0', STR_PAD_LEFT);
                $serial = ProductSerial::withoutGlobalScopes()->updateOrCreate(
                    ['shop_id' => $shop->id, 'imei' => $serialValue],
                    [
                        'product_id' => $product->id,
                        'cost' => $cost,
                        'warranty_expiry' => now()->subDays($daysAgo)->addYear()->toDateString(),
                        'status' => $u < $sold ? 'sold' : 'in_stock',
                    ]
                );
                if ($pi === 0 && $u === 0) {
                    $firstServiceTicketSerial = $serial;
                }
            }
        }

        // ---- quantity-based accessories + a few bulk purchases ----
        $accModels = [];
        foreach (self::ACCESSORIES as $ai => [$name, $catName, $price, $cost, $stock, $emoji]) {
            $accModels[$ai] = Product::withoutGlobalScopes()->updateOrCreate(
                ['shop_id' => $shop->id, 'name' => $name],
                [
                    'category_id' => $cats[$catName]->id,
                    'unit_id' => $pieceUnitId,
                    'emoji' => $emoji,
                    'barcode' => 'GT-AC-'.str_pad((string) ($ai + 1), 3, '0', STR_PAD_LEFT),
                    'cost' => $cost, 'price' => $price, 'stock' => $stock,
                    'deleted_at' => null,
                ]
            );
        }
        foreach ([[1, 58, 42500, 'cash'], [2, 40, 29800, 'credit'], [4, 25, 36400, 'bank'], [0, 12, 18600, 'cash']] as $bi => [$supIdx, $days, $amount, $method]) {
            if ($method === 'credit') {
                $supplierPayable[$supIdx] += $amount;
            }
            Purchase::withoutGlobalScopes()->firstOrCreate(
                ['shop_id' => $shop->id, 'memo' => 'PINV-'.str_pad((string) (401 + $bi), 4, '0', STR_PAD_LEFT)],
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

        // ---- sales: every sold serial-tracked unit gets its own invoice,
        // linked to its serial; accessory sales follow the fixed plan above ----
        $invoice = 3001;
        $customerTotals = [];
        $modes = ['cash', 'bkash', 'nagad', 'credit', 'cash', 'bkash'];

        foreach (self::SERIAL_PRODUCTS as $pi => [$name, $catName, $price, $cost, $bought, $sold, $supIdx, $daysAgo, $serialSeed]) {
            [$kind, $seed] = explode(':', $serialSeed, 2);
            for ($u = 0; $u < $sold; $u++) {
                $custIdx = ($pi + $u) % count($customers);
                $mode = $modes[($pi + $u) % count($modes)];
                // spread evenly across the ~6 months since this batch arrived,
                // instead of clustering right after purchase — a real shop
                // sells a batch gradually over its whole shelf life, and the
                // Business Dashboard's revenue trend needs real month-to-month
                // variation to actually demonstrate anything.
                $saleDaysAgo = max(1, $daysAgo - 5 - (int) round(($u + 1) * ($daysAgo - 5) / ($sold + 1)));
                $serialValue = $kind === 'imei' ? self::imei($seed, $u) : $seed.'-'.str_pad((string) ($u + 1), 2, '0', STR_PAD_LEFT);
                $this->createSale($shop, 'INV-'.$invoice++, $saleDaysAgo, $mode, $customers[$custIdx], [
                    ['product' => $serialModels[$pi], 'qty' => 1, 'serial' => $serialValue],
                ], $customerTotals, $custIdx);
            }
        }

        foreach (self::accessorySales(count(self::ACCESSORIES), count($customers)) as [$daysAgo, $mode, $custIdx, $items]) {
            $lines = array_map(fn ($it) => ['product' => $accModels[$it[0]], 'qty' => $it[1], 'serial' => null], $items);
            $this->createSale($shop, 'INV-'.$invoice++, $daysAgo, $mode, $custIdx !== null ? $customers[$custIdx] : null, $lines, $customerTotals, $custIdx);
        }

        foreach ($customerTotals as $ci => $t) {
            $customers[$ci]->update(['due' => $t['due'], 'total_spent' => $t['spent'], 'visits' => $t['visits']]);
        }

        Shop::withoutGlobalScopes()->whereKey($shop->id)->update(['invoice_counter' => max($shop->invoice_counter, $invoice)]);

        $rent = ExpenseCategory::withoutGlobalScopes()->firstOrCreate(['shop_id' => $shop->id, 'name' => 'দোকান ভাড়া'], ['emoji' => '🏠']);
        $bill = ExpenseCategory::withoutGlobalScopes()->firstOrCreate(['shop_id' => $shop->id, 'name' => 'বিদ্যুৎ বিল'], ['emoji' => '💡']);
        Expense::withoutGlobalScopes()->firstOrCreate(['shop_id' => $shop->id, 'expense_category_id' => $rent->id, 'memo' => 'মাসিক দোকান ভাড়া'], ['amount' => 35000, 'method' => 'cash', 'date' => now()->subDays(12)->toDateString()]);
        Expense::withoutGlobalScopes()->firstOrCreate(['shop_id' => $shop->id, 'expense_category_id' => $bill->id, 'memo' => 'বিদ্যুৎ বিল'], ['amount' => 4800, 'method' => 'cash', 'date' => now()->subDays(8)->toDateString()]);

        // ---- employees + attendance + one payroll run ----
        $employees = [];
        foreach (self::EMPLOYEES as [$name, $ePhone, $designation, $salaryType, $basicSalary, $joinDaysAgo]) {
            $employees[] = Employee::withoutGlobalScopes()->updateOrCreate(
                ['shop_id' => $shop->id, 'phone' => $ePhone],
                [
                    'name' => $name, 'designation' => $designation, 'salary_type' => $salaryType,
                    'basic_salary' => $basicSalary, 'joining_date' => now()->subDays($joinDaysAgo)->toDateString(),
                    'status' => 'active',
                ]
            );
        }
        $lastMonth = now()->subMonthNoOverflow();
        foreach ($employees as $employee) {
            for ($d = 1; $d <= 26; $d++) {
                $date = $lastMonth->copy()->startOfMonth()->addDays($d - 1);
                if ($date->greaterThan(now())) {
                    break;
                }
                Attendance::withoutGlobalScopes()->firstOrCreate(
                    ['shop_id' => $shop->id, 'employee_id' => $employee->id, 'date' => $date->toDateString()],
                    ['status' => $d % 11 === 0 ? 'absent' : 'present']
                );
            }
            $month = $lastMonth->format('Y-m');
            $preview = Payroll::preview($employee, $month);
            SalaryPayment::withoutGlobalScopes()->firstOrCreate(
                ['shop_id' => $shop->id, 'employee_id' => $employee->id, 'month' => $month],
                [
                    'basic_salary' => $employee->basic_salary,
                    'present_days' => $preview['present_days'],
                    'absent_days' => $preview['absent_days'],
                    'attendance_deduction' => $preview['attendance_deduction'],
                    'bonus' => 0,
                    'advance_deduction' => 0,
                    'net_paid' => $preview['suggested_net'],
                    'method' => 'cash',
                    'paid_date' => $lastMonth->copy()->endOfMonth()->toDateString(),
                ]
            );
        }
        if ($employees) {
            SalaryAdvance::withoutGlobalScopes()->firstOrCreate(
                ['shop_id' => $shop->id, 'employee_id' => $employees[0]->id, 'date' => now()->subDays(10)->toDateString()],
                ['amount' => 3000, 'outstanding' => 3000, 'method' => 'cash', 'note' => 'ঈদের অগ্রিম']
            );
        }

        // ---- one warranty/service ticket, demonstrating the existing
        // repair/warranty-claim module against a real sold serial ----
        if ($firstServiceTicketSerial) {
            ServiceTicket::withoutGlobalScopes()->firstOrCreate(
                ['shop_id' => $shop->id, 'product_serial_id' => $firstServiceTicketSerial->id],
                [
                    'customer_id' => $customers[0]->id,
                    'product_id' => $firstServiceTicketSerial->product_id,
                    'device_label' => self::SERIAL_PRODUCTS[0][0],
                    'problem_description' => 'চার্জ হচ্ছে না, চার্জিং পোর্ট লুজ',
                    'technician_name' => self::EMPLOYEES[1][0] ?? null,
                    'status' => 'in_progress',
                    'is_warranty_claim' => true,
                    'received_at' => now()->subDays(2),
                ]
            );
        }
    }

    private function seedBranch(Shop $mainShop): void
    {
        $branch = Shop::withoutGlobalScopes()
            ->where('parent_shop_id', $mainShop->id)
            ->where('name', self::SHOP_NAME.' - উত্তরা শাখা')
            ->first();

        if (! $branch) {
            $branch = Shop::create([
                'name' => self::SHOP_NAME.' - উত্তরা শাখা',
                'phone' => 'PENDING-'.Str::random(8),
                'area' => 'উত্তরা, ঢাকা',
                'parent_shop_id' => $mainShop->id,
                'business_type_id' => $mainShop->business_type_id,
                'name_en' => $mainShop->name_en.' - Uttara Branch',
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
            $branch->features()->sync(Feature::whereIn('key', self::FEATURE_KEYS)->pluck('id'));
        }

        $this->seedBranchSales($branch);

        $warehouse = $this->seedWarehouse($mainShop);
        $this->seedTransfers($mainShop, $warehouse, $branch);
    }

    /**
     * A warehouse is just a normal branch with is_warehouse=true (pure
     * label/icon — see the migration's own docblock, confirmed to Khaled:
     * it does NOT restrict selling or force stock through it in this
     * codebase) — so the demo shows that literally, instead of inventing
     * special warehouse behavior that doesn't exist anywhere else.
     */
    private function seedWarehouse(Shop $mainShop): Shop
    {
        $warehouse = Shop::withoutGlobalScopes()
            ->where('parent_shop_id', $mainShop->id)
            ->where('name', self::SHOP_NAME.' - সেন্ট্রাল ওয়্যারহাউজ')
            ->first();

        if ($warehouse) {
            return $warehouse;
        }

        $warehouse = Shop::create([
            'name' => self::SHOP_NAME.' - সেন্ট্রাল ওয়্যারহাউজ',
            'phone' => 'PENDING-'.Str::random(8),
            'area' => 'তেজগাঁও, ঢাকা',
            'parent_shop_id' => $mainShop->id,
            'business_type_id' => $mainShop->business_type_id,
            'name_en' => $mainShop->name_en.' - Central Warehouse',
            'owner_name' => $mainShop->owner_name,
            'sales_mode' => $mainShop->sales_mode,
            'lang' => $mainShop->lang,
            'plan' => $mainShop->plan,
            'account_type' => $mainShop->account_type,
            'status' => 'active',
            'is_warehouse' => true,
            'subscription_start' => $mainShop->subscription_start,
            'subscription_expiry' => $mainShop->subscription_expiry,
            'vat_mode' => $mainShop->vat_mode,
            'invoice_counter' => 1000,
            'onboarded_at' => now(),
        ]);
        $warehouse->features()->sync(Feature::whereIn('key', self::FEATURE_KEYS)->pluck('id'));

        // stocked here first, like a real distributor-fed warehouse, before
        // any of it moves out to a selling branch
        $pieceUnitId = Unit::withoutGlobalScopes()->where('shop_id', $warehouse->id)->where('name', 'like', '%পিস%')->value('id');
        $cat = ProductCategory::withoutGlobalScopes()->updateOrCreate(['shop_id' => $warehouse->id, 'name' => 'সিসিটিভি ক্যামেরা'], ['emoji' => '📹']);
        foreach (array_slice(self::ACCESSORIES, 0, 4) as $ai => [$name, , $price, $cost, , $emoji]) {
            Product::withoutGlobalScopes()->updateOrCreate(
                ['shop_id' => $warehouse->id, 'name' => $name],
                ['category_id' => $cat->id, 'unit_id' => $pieceUnitId, 'emoji' => $emoji, 'barcode' => 'GT-WH-'.str_pad((string) ($ai + 1), 3, '0', STR_PAD_LEFT), 'cost' => $cost, 'price' => $price, 'stock' => 60, 'deleted_at' => null]
            );
        }
        Purchase::withoutGlobalScopes()->firstOrCreate(
            ['shop_id' => $warehouse->id, 'memo' => 'PINV-WH-0001'],
            ['supplier' => 'Dhaka CCTV Wholesale', 'amount' => 180000, 'method' => 'bank', 'date' => now()->subDays(165)->toDateString()]
        );

        return $warehouse;
    }

    /**
     * Real StockTransfer rows (not raw stock edits) demonstrating both
     * directions Khaled explicitly asked to see: warehouse->branch and
     * branch->branch. Replicates StockTransferController::store()'s own
     * stock effect (decrement source, increment/create destination by
     * barcode-then-name match) directly rather than bootstrapping a full
     * HTTP+Auth context inside a console command — same reasoning as every
     * other direct-model seeding in this file.
     */
    private function seedTransfers(Shop $mainShop, Shop $warehouse, Shop $branch): void
    {
        $owner = User::where('shop_id', $mainShop->id)->where('role', 'owner')->first();

        // W -> B: warehouse sends CCTV cameras to the Uttara branch
        $whProduct = Product::withoutGlobalScopes()->where('shop_id', $warehouse->id)->where('barcode', 'GT-WH-001')->first();
        if ($whProduct && ! StockTransfer::withoutGlobalScopes()->where('from_shop_id', $warehouse->id)->where('to_shop_id', $branch->id)->exists()) {
            DB::transaction(function () use ($warehouse, $branch, $whProduct, $owner) {
                $qty = 15;
                $whProduct->decrement('stock', $qty);
                // stock is only initialized to 0 when this is the branch's
                // FIRST time receiving this product — never reset an
                // existing row (it may already carry stock from
                // seedBranchSales or an earlier transfer).
                $existing = Product::withoutGlobalScopes()->where('shop_id', $branch->id)->where('name', $whProduct->name)->first();
                $attrs = ['category_id' => ProductCategory::withoutGlobalScopes()->firstOrCreate(['shop_id' => $branch->id, 'name' => 'সিসিটিভি ক্যামেরা'], ['emoji' => '📹'])->id,
                    'unit_id' => $whProduct->unit_id, 'emoji' => $whProduct->emoji, 'barcode' => $whProduct->barcode,
                    'cost' => $whProduct->cost, 'price' => $whProduct->price];
                if (! $existing) {
                    $attrs['stock'] = 0;
                }
                $branchProduct = Product::withoutGlobalScopes()->updateOrCreate(['shop_id' => $branch->id, 'name' => $whProduct->name], $attrs);
                $branchProduct->increment('stock', $qty);
                StockTransfer::create([
                    'shop_id' => $warehouse->id, 'from_shop_id' => $warehouse->id, 'to_shop_id' => $branch->id,
                    'from_product_id' => $whProduct->id, 'to_product_id' => $branchProduct->id,
                    'product_name' => $whProduct->name, 'qty' => $qty, 'user_id' => $owner?->id, 'status' => 'completed',
                    'created_at' => now()->subDays(60), 'updated_at' => now()->subDays(60),
                ]);
            });
        }

        // B -> B: main shop sends a laptop to the Uttara branch
        $mainProduct = Product::withoutGlobalScopes()->where('shop_id', $mainShop->id)->where('name', 'HP 15 Core i3 12th Gen')->first();
        if ($mainProduct && $mainProduct->stock > 1 && ! StockTransfer::withoutGlobalScopes()->where('from_shop_id', $mainShop->id)->where('to_shop_id', $branch->id)->exists()) {
            DB::transaction(function () use ($mainShop, $branch, $mainProduct, $owner) {
                $qty = 1;
                $mainProduct->decrement('stock', $qty);
                $existing = Product::withoutGlobalScopes()->where('shop_id', $branch->id)->where('name', $mainProduct->name)->first();
                $attrs = ['category_id' => ProductCategory::withoutGlobalScopes()->firstOrCreate(['shop_id' => $branch->id, 'name' => 'ল্যাপটপ ও কম্পিউটার'], ['emoji' => '💻'])->id,
                    'unit_id' => $mainProduct->unit_id, 'emoji' => $mainProduct->emoji, 'barcode' => $mainProduct->barcode,
                    'cost' => $mainProduct->cost, 'price' => $mainProduct->price];
                if (! $existing) {
                    $attrs['stock'] = 0;
                }
                $branchProduct = Product::withoutGlobalScopes()->updateOrCreate(['shop_id' => $branch->id, 'name' => $mainProduct->name], $attrs);
                $branchProduct->increment('stock', $qty);
                StockTransfer::create([
                    'shop_id' => $mainShop->id, 'from_shop_id' => $mainShop->id, 'to_shop_id' => $branch->id,
                    'from_product_id' => $mainProduct->id, 'to_product_id' => $branchProduct->id,
                    'product_name' => $mainProduct->name, 'qty' => $qty, 'user_id' => $owner?->id, 'status' => 'completed',
                    'created_at' => now()->subDays(30), 'updated_at' => now()->subDays(30),
                ]);
            });
        }
    }

    /**
     * The branch had zero of its own sales/stock — the Business Dashboard's
     * Branch Leaderboard always showed "৳0" for it (real live gap, caught
     * only by actually checking the demo, same as the hr_payroll/
     * service_billing feature gap earlier). Each branch keeps its own
     * independent Product rows (no shared catalog row across branches in
     * this codebase), so a smaller parallel accessory catalog + 6 months
     * of its own walk-in sales is seeded directly here rather than via
     * CatalogSync (keeps this command self-contained, same style as the
     * main shop's own product creation above).
     */
    private function seedBranchSales(Shop $branch): void
    {
        $pieceUnitId = Unit::withoutGlobalScopes()->where('shop_id', $branch->id)
            ->where('name', 'like', '%পিস%')->value('id');
        $catName = 'চার্জার ও ক্যাবল';
        $cat = ProductCategory::withoutGlobalScopes()->updateOrCreate(
            ['shop_id' => $branch->id, 'name' => $catName], ['emoji' => '🔌']
        );

        $accessories = array_slice(self::ACCESSORIES, 0, 12);
        $accModels = [];
        foreach ($accessories as $ai => [$name, , $price, $cost, $stock, $emoji]) {
            // stock is only ever set on first creation — a re-run must NOT
            // reset it, since seedTransfers() may have since incremented
            // this exact product via a warehouse/branch transfer (real bug
            // caught live: a re-run was silently undoing the transfer).
            $product = Product::withoutGlobalScopes()->where('shop_id', $branch->id)->where('name', $name)->first();
            $attrs = ['category_id' => $cat->id, 'unit_id' => $pieceUnitId, 'emoji' => $emoji, 'barcode' => 'GT-UTR-'.str_pad((string) ($ai + 1), 3, '0', STR_PAD_LEFT), 'cost' => $cost, 'price' => $price, 'deleted_at' => null];
            if (! $product) {
                $attrs['stock'] = $stock;
            }
            $accModels[$ai] = Product::withoutGlobalScopes()->updateOrCreate(['shop_id' => $branch->id, 'name' => $name], $attrs);
        }

        Purchase::withoutGlobalScopes()->firstOrCreate(
            ['shop_id' => $branch->id, 'memo' => 'PINV-UTR-0001'],
            ['supplier' => 'Global Electronics Distribution', 'amount' => 65000, 'method' => 'bank', 'date' => now()->subDays(170)->toDateString()]
        );

        $invoice = $branch->invoice_counter ?: 1000;
        $modes = ['cash', 'cash', 'bkash', 'nagad'];
        for ($day = 175; $day >= 1; $day -= 5) {
            $seed = $day * 5;
            $accIdx = $seed % count($accessories);
            $qty = 1 + ($seed % 3);
            $product = $accModels[$accIdx];
            $subtotal = $product->price * $qty;
            $invoiceNo = 'INV-'.(++$invoice);
            $sale = Sale::withoutGlobalScopes()->firstOrCreate(
                ['shop_id' => $branch->id, 'invoice_no' => $invoiceNo],
                [
                    'date' => now()->subDays($day)->toDateString(),
                    'time' => sprintf('%02d:%02d:00', 10 + ($day % 9), ($day * 7) % 60),
                    'subtotal' => $subtotal, 'discount' => 0, 'vat' => 0, 'total' => $subtotal,
                    'profit' => ($product->price - $product->cost) * $qty, 'payment_mode' => $modes[$seed % count($modes)],
                ]
            );
            if ($sale->wasRecentlyCreated) {
                SaleItem::create([
                    'shop_id' => $branch->id, 'sale_id' => $sale->id, 'product_id' => $product->id,
                    'product_name' => $product->name, 'qty' => $qty, 'price' => $product->price, 'cost' => $product->cost,
                ]);
            }
        }

        Shop::withoutGlobalScopes()->whereKey($branch->id)->update(['invoice_counter' => $invoice]);
    }

    /** @param  array<int, array{product: Product, qty: int, serial: ?string}>  $lines */
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
            $serialId = $l['serial']
                ? ProductSerial::withoutGlobalScopes()->where('shop_id', $shop->id)->where('imei', $l['serial'])->value('id')
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
            $this->info('কোনো Global Touch ডেমো পাওয়া যায়নি — করার কিছু নেই।');

            return self::SUCCESS;
        }

        DB::transaction(function () use ($shop) {
            // both the Uttara branch AND the central warehouse are children here
            foreach (Shop::withoutGlobalScopes()->where('parent_shop_id', $shop->id)->get() as $child) {
                SaleItem::withoutGlobalScopes()->where('shop_id', $child->id)->delete();
                Sale::withoutGlobalScopes()->where('shop_id', $child->id)->delete();
                Purchase::withoutGlobalScopes()->where('shop_id', $child->id)->delete();
                StockTransfer::withoutGlobalScopes()->where('from_shop_id', $child->id)->orWhere('to_shop_id', $child->id)->delete();
                Product::withoutGlobalScopes()->where('shop_id', $child->id)->forceDelete();
                ProductCategory::withoutGlobalScopes()->where('shop_id', $child->id)->delete();
                $child->features()->detach();
                $child->delete();
            }

            StockTransfer::withoutGlobalScopes()->where('from_shop_id', $shop->id)->orWhere('to_shop_id', $shop->id)->delete();
            ServiceTicketItem::withoutGlobalScopes()->where('shop_id', $shop->id)->delete();
            ServiceTicket::withoutGlobalScopes()->where('shop_id', $shop->id)->delete();
            SalaryPayment::withoutGlobalScopes()->where('shop_id', $shop->id)->delete();
            SalaryAdvance::withoutGlobalScopes()->where('shop_id', $shop->id)->delete();
            Attendance::withoutGlobalScopes()->where('shop_id', $shop->id)->delete();
            Employee::withoutGlobalScopes()->where('shop_id', $shop->id)->delete();
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

        $this->info('✅ Global Touch ডেমো মুছে ফেলা হয়েছে।');

        return self::SUCCESS;
    }
}
