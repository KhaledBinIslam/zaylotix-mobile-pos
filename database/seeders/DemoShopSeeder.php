<?php

namespace Database\Seeders;

use App\Models\BusinessType;
use App\Models\Customer;
use App\Models\Damage;
use App\Models\Expense;
use App\Models\ExpenseCategory;
use App\Models\Feature;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\ProductUnit;
use App\Models\Purchase;
use App\Models\RestaurantTable;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\Shop;
use App\Models\Unit;
use App\Models\User;
use App\Support\SeedGuard;
use App\Support\ShopProvisioner;
use App\Support\Tenancy;
use Illuminate\Database\Seeder;

/**
 * Every demo shop's owner login shares one password, controlled by
 * DEMO_SHOP_PASSWORD in .env — defaults to '1234' for local dev (see
 * SeedGuard), but refuses to run at all in production if that var is
 * missing, blank, or still a well-known weak value, since these are
 * otherwise guessable, publicly-documented demo phone numbers.
 *
 * Idempotent by design (firstOrCreate throughout, keyed by phone/name/
 * invoice_no) — safe to re-run against demo shops that already exist,
 * which only fills in whatever's actually missing instead of erroring on
 * the phone-number unique constraint or duplicating rows. This matters in
 * practice, not just in theory: it's exactly what let `php artisan
 * db:seed --class=DemoShopSeeder` repair every demo shop's catalog after
 * something (outside this seeder) had deleted their products/tables/sales
 * directly against the dev database — see the commit this comment shipped
 * in for the full incident writeup.
 */
class DemoShopSeeder extends Seeder
{
    private string $password;

    public function run(): void
    {
        $this->password = SeedGuard::password('DEMO_SHOP_PASSWORD', '1234');

        $this->groceryFlagship();
        $this->lightDemo('pharmacy', 'Sasto Pharmacy', '01700000002', 'Dhaka');
        $this->lightDemo('mobile', 'City Mobile Center', '01700000003', 'Dhaka');
        $this->lightDemo('clothing', 'Fashion Point', '01700000004', 'Sylhet');
        $this->lightDemo('cosmetics', 'Glow Cosmetics', '01700000005', 'Khulna');
        $this->lightDemo('supershop', 'Agora Mart Demo', '01700000006', 'Dhaka');
        $this->lightDemo('general', 'General Store Demo', '01700000007', 'Rajshahi');
        $this->restaurantDemo();
    }

    /**
     * A demo shop is identified by its well-known phone number — if one
     * already exists (this seeder ran before, or the shop survived while
     * only its products/etc were lost), reuse it as-is rather than calling
     * ShopProvisioner::provision() again, which would try to INSERT a
     * second User with that same phone and fail on its unique constraint.
     */
    private function provisionOrReuse(string $phone, array $shopAttrs, string $ownerName, array $featureKeys): Shop
    {
        $existing = Shop::withoutGlobalScopes()->where('phone', $phone)->first();
        if ($existing) {
            return $existing;
        }

        return ShopProvisioner::provision($shopAttrs, $ownerName, $phone, null, $this->password, $featureKeys);
    }

    /**
     * Restaurant needed its own method (not lightDemo) — it's the one
     * vertical with a dedicated Tables/Order screen instead of the shared
     * POS, so a demo shop needs at least one table to actually show that
     * flow, which lightDemo's generic single-product setup doesn't create.
     */
    private function restaurantDemo(): void
    {
        $type = BusinessType::where('slug', 'restaurant')->first();
        if (! $type) {
            return;
        }

        $phone = '01700000008';
        $shop = $this->provisionOrReuse($phone, [
            'business_type_id' => $type->id,
            'name' => 'নমুনা রেস্টুরেন্ট',
            'name_en' => 'Demo Restaurant',
            'phone' => $phone,
            'area' => 'চট্টগ্রাম',
            'owner_name' => 'Demo Restaurant Owner',
            'sales_mode' => 'both',
            'lang' => 'bn',
            'plan' => 'trial',
            'status' => 'active',
            'staff_limit' => 10, // demo shops showcase the full app, not the Business-tier default cap
            'subscription_start' => now()->toDateString(),
            'subscription_expiry' => now()->addDays(14)->toDateString(),
            'cash_balance' => 5000,
            'bank_balance' => 0,
            'capital' => 20000,
        ], 'Demo Restaurant Owner', ['memo_whatsapp', 'memo_print', 'restaurant_tables', 'purchases', 'damages', 'low_stock_alerts', 'accounts', 'expenses', 'reports', 'export', 'vat', 'cashier_management', 'activity_log']);

        Tenancy::set($shop->id);

        $cat = ProductCategory::where('shop_id', $shop->id)->first();
        $unit = Unit::where('shop_id', $shop->id)->first();

        $menu = [
            ['name' => 'চিকেন বিরিয়ানি', 'name_en' => 'Chicken Biryani', 'emoji' => '🍛', 'price' => 220, 'cost' => 140],
            ['name' => 'বিফ কাবাব', 'name_en' => 'Beef Kabab', 'emoji' => '🍢', 'price' => 180, 'cost' => 110],
            ['name' => 'কোল্ড ড্রিংকস', 'name_en' => 'Cold Drinks', 'emoji' => '🥤', 'price' => 40, 'cost' => 25],
        ];
        foreach ($menu as $m) {
            Product::firstOrCreate(
                ['shop_id' => $shop->id, 'name' => $m['name']],
                [
                    'category_id' => $cat?->id,
                    'unit_id' => $unit?->id,
                    'name_en' => $m['name_en'],
                    'emoji' => $m['emoji'],
                    'cost' => $m['cost'],
                    'price' => $m['price'],
                    // TableOrderController::addItem() decrements stock
                    // exactly like retail (see its class docblock) — 0
                    // here isn't "unlimited", it's "sold out", so the demo
                    // needs a real number or the whole ordering flow looks
                    // broken out of the box (this was the actual bug:
                    // Order.vue's addItem() used to silently no-op on
                    // stock<=0 instead of showing the server's real
                    // "insufficient stock" error).
                    'stock' => 50,
                ]
            );
        }

        foreach (['টেবিল ১', 'টেবিল ২', 'টেবিল ৩', 'টেবিল ৪'] as $name) {
            RestaurantTable::firstOrCreate(['shop_id' => $shop->id, 'name' => $name], ['status' => 'free']);
        }

        Tenancy::clear();
    }

    /** Full-fidelity port of the byapari-app.html demo data (Khaled Enterprise). */
    private function groceryFlagship(): void
    {
        $type = BusinessType::where('slug', 'grocery')->first();

        $phone = '01979894356';
        $shop = $this->provisionOrReuse($phone, [
            'business_type_id' => $type->id,
            'name' => 'Khaled Enterprise',
            'name_en' => 'Khaled Enterprise',
            'phone' => $phone,
            'area' => 'চট্টগ্রাম',
            'owner_name' => 'Khaled Bin Islam',
            'sales_mode' => 'both',
            'lang' => 'bn',
            'plan' => 'yearly',
            'status' => 'active',
            'staff_limit' => 10, // demo shops showcase the full app, not the Business-tier default cap
            'subscription_start' => now()->subMonths(2)->toDateString(),
            'subscription_expiry' => now()->addYear()->toDateString(),
            'cash_balance' => 18500,
            'bank_balance' => 42000,
            'capital' => 50000,
            'vat_mode' => 'none',
            // flagship demo — every feature switched on so it showcases the
            // whole app; individual shops normally get a curated subset (see
            // lightDemo() below for an example of that tiering)
        ], 'Khaled Bin Islam', Feature::pluck('key')->all());

        // ShopProvisioner::provision() clears the tenant context before returning
        // (it's not authenticated as anyone) — every tenant-scoped read below
        // needs it bound again, or it silently sees zero rows (safe-default
        // scope), not an error, which is exactly the bug that slipped through
        // here before: category_id/unit_id looked up as NULL on every product.
        Tenancy::set($shop->id);

        $cat = ProductCategory::where('shop_id', $shop->id)->get()->keyBy('name_en');
        $unit = Unit::where('shop_id', $shop->id)->first();
        $piecesUnit = Unit::where('shop_id', $shop->id)->where('code', 'pcs')->first();
        $boxUnit = Unit::firstOrCreate(['shop_id' => $shop->id, 'name' => 'বক্স'], ['name_en' => 'Box', 'code' => 'box']);

        $products = [
            ['name' => 'তীর সয়াবিন তেল ১ লিটার', 'name_en' => 'Teer Soybean Oil 1L', 'emoji' => '🫒', 'cat' => 'Oil & Ghee', 'price' => 185, 'cost' => 168, 'stock' => 24, 'barcode' => '8901234500017'],
            ['name' => 'ফ্রেশ চিনি ১ কেজি', 'name_en' => 'Fresh Sugar 1kg', 'emoji' => '🍬', 'cat' => 'Grocery', 'price' => 135, 'cost' => 122, 'stock' => 40, 'barcode' => '8901234500024'],
            ['name' => 'রূপচাঁদা আটা ২ কেজি', 'name_en' => 'Rupchanda Atta 2kg', 'emoji' => '🌾', 'cat' => 'Rice & Flour', 'price' => 130, 'cost' => 118, 'stock' => 6, 'barcode' => '8901234500031'],
            ['name' => 'প্রাণ ফ্রুটো ২৫০ মিলি', 'name_en' => 'Pran Frooto 250ml', 'emoji' => '🧃', 'cat' => 'Drinks', 'price' => 30, 'cost' => 24, 'stock' => 60, 'barcode' => '8901234500048'],
            ['name' => 'রাধুনি হলুদ গুড়া ১০০গ্রা', 'name_en' => 'Radhuni Turmeric 100g', 'emoji' => '🌶️', 'cat' => 'Spices', 'price' => 45, 'cost' => 37, 'stock' => 3, 'barcode' => '8901234500055'],
            ['name' => 'কোহিনূর চাল ৫ কেজি', 'name_en' => 'Kohinoor Rice 5kg', 'emoji' => '🍚', 'cat' => 'Rice & Flour', 'price' => 420, 'cost' => 390, 'stock' => 15, 'barcode' => '8901234500062'],
            ['name' => 'ক্লোজআপ পেস্ট ১০০গ্রা', 'name_en' => 'Closeup 100g', 'emoji' => '🪥', 'cat' => 'Grocery', 'price' => 95, 'cost' => 82, 'stock' => 0, 'barcode' => '8901234500079'],
            ['name' => 'সেভেন আপ ৫০০ মিলি', 'name_en' => '7Up 500ml', 'emoji' => '🥤', 'cat' => 'Drinks', 'price' => 35, 'cost' => 28, 'stock' => 48, 'barcode' => '8901234500086'],
            ['name' => 'ইয়াম ইয়াম নুডলস', 'name_en' => 'Yum Yum Noodles', 'emoji' => '🍜', 'cat' => 'Grocery', 'price' => 20, 'cost' => 15, 'stock' => 90, 'barcode' => '8901234500093'],
            ['name' => 'ডানো গুড়া দুধ ৫০০গ্রা', 'name_en' => 'Dano Milk 500g', 'emoji' => '🥛', 'cat' => 'Grocery', 'price' => 450, 'cost' => 415, 'stock' => 9, 'barcode' => '8901234500109'],
        ];

        $productModels = [];
        foreach ($products as $i => $p) {
            $productModels[] = Product::firstOrCreate(
                ['shop_id' => $shop->id, 'name' => $p['name']],
                [
                    'category_id' => $cat[$p['cat']]->id ?? null,
                    'unit_id' => $unit?->id,
                    'name_en' => $p['name_en'],
                    'emoji' => $p['emoji'],
                    'barcode' => $p['barcode'],
                    'cost' => $p['cost'],
                    'price' => $p['price'],
                    'discount_price' => $i === 1 ? 125 : null, // demo: sugar on discount, shows on printed barcode label
                    'stock' => $p['stock'],
                ]
            );
        }

        // unit_conversion demo: a box of Center Fruit candy, sellable whole
        // or broken down piece-by-piece — stock is tracked in pieces.
        $centerFruit = Product::firstOrCreate(
            ['shop_id' => $shop->id, 'name' => 'সেন্টার ফ্রুট বক্স (১০০ পিস)'],
            [
                'category_id' => $cat['Snacks']->id ?? null,
                'unit_id' => $piecesUnit?->id,
                'name_en' => 'Center Fruit Box (100 pcs)',
                'emoji' => '🍬',
                'barcode' => '8901234500200',
                'cost' => 2, // cost per piece
                'price' => 3, // price per piece
                'stock' => 300, // 3 boxes worth, tracked in pieces
            ]
        );
        ProductUnit::firstOrCreate(
            ['shop_id' => $shop->id, 'product_id' => $centerFruit->id, 'unit_id' => $boxUnit->id],
            [
                'factor' => 100, // 1 box = 100 pieces
                'price' => 250, // whole-box price (cheaper per-piece than singles)
            ]
        );
        $productModels[] = $centerFruit;

        $customerSeed = [
            ['name' => 'করিম মিয়া', 'phone' => '01812-111222', 'due' => 1250, 'total_spent' => 18400, 'visits' => 23],
            ['name' => 'সালমা বেগম', 'phone' => '01911-333444', 'due' => 640, 'total_spent' => 9200, 'visits' => 14],
            ['name' => 'আব্দুল্লাহ', 'phone' => '01677-555666', 'due' => 2100, 'total_spent' => 31500, 'visits' => 41],
            ['name' => 'নাসরিন আক্তার', 'phone' => '01555-777888', 'due' => 0, 'total_spent' => 5600, 'visits' => 8],
            ['name' => 'কাসিম উদ্দিন', 'phone' => '01722-999000', 'due' => 380, 'total_spent' => 12750, 'visits' => 19],
        ];
        $customers = collect($customerSeed)->map(fn ($c) => Customer::firstOrCreate(
            ['shop_id' => $shop->id, 'phone' => $c['phone']],
            ['name' => $c['name'], 'due' => $c['due'], 'total_spent' => $c['total_spent'], 'visits' => $c['visits']]
        ))->all();

        $sales = [
            ['no' => 'INV-1042', 'date' => now()->toDateString(), 'items' => [[0, 3]], 'total' => 405, 'mode' => 'cash', 'cust' => null, 'profit' => 58],
            ['no' => 'INV-1041', 'date' => now()->toDateString(), 'items' => [[0, 1]], 'total' => 185, 'mode' => 'bkash', 'cust' => null, 'profit' => 17],
            ['no' => 'INV-1040', 'date' => now()->subDay()->toDateString(), 'items' => [[5, 1]], 'total' => 420, 'mode' => 'credit', 'cust' => 0, 'profit' => 30],
            ['no' => 'INV-1039', 'date' => now()->subDay()->toDateString(), 'items' => [[3, 5]], 'total' => 150, 'mode' => 'cash', 'cust' => null, 'profit' => 30],
            ['no' => 'INV-1038', 'date' => now()->subDays(2)->toDateString(), 'items' => [[1, 4]], 'total' => 540, 'mode' => 'cash', 'cust' => null, 'profit' => 52],
        ];

        foreach ($sales as $i => $s) {
            $sale = Sale::firstOrCreate(
                ['shop_id' => $shop->id, 'invoice_no' => $s['no']],
                [
                    'customer_id' => $s['cust'] !== null ? $customers[$s['cust']]->id : null,
                    'date' => $s['date'],
                    'time' => sprintf('%02d:%02d:00', 10 + $i, 15 * $i % 60),
                    'subtotal' => $s['total'],
                    'discount' => 0,
                    'vat' => 0,
                    'total' => $s['total'],
                    'profit' => $s['profit'],
                    'payment_mode' => $s['mode'],
                ]
            );

            // firstOrCreate can't tell us "was this new" via truthiness, but
            // wasRecentlyCreated does — skip re-adding line items to a sale
            // that already existed (sale_items has no natural unique key to
            // firstOrCreate against, so re-running would otherwise duplicate
            // them on every call even though the Sale itself is reused)
            if (! $sale->wasRecentlyCreated) {
                continue;
            }

            foreach ($s['items'] as [$pIdx, $qty]) {
                $p = $productModels[$pIdx];
                SaleItem::create([
                    'shop_id' => $shop->id,
                    'sale_id' => $sale->id,
                    'product_id' => $p->id,
                    'product_name' => $p->name,
                    'qty' => $qty,
                    'price' => $p->price,
                    'cost' => $p->cost,
                ]);
            }
        }

        $shop->update(['invoice_counter' => max($shop->invoice_counter, 1042)]);

        $expCat = ExpenseCategory::firstOrCreate(['shop_id' => $shop->id, 'name' => 'দোকান ভাড়া'], ['name_en' => 'Shop rent', 'emoji' => '🏠']);
        $expCat2 = ExpenseCategory::firstOrCreate(['shop_id' => $shop->id, 'name' => 'বিদ্যুৎ বিল'], ['name_en' => 'Electricity', 'emoji' => '💡']);
        Expense::firstOrCreate(['shop_id' => $shop->id, 'expense_category_id' => $expCat->id, 'memo' => 'জুলাই ভাড়া'], ['amount' => 8000, 'method' => 'cash', 'date' => now()->subDay()->toDateString()]);
        Expense::firstOrCreate(['shop_id' => $shop->id, 'expense_category_id' => $expCat2->id, 'memo' => 'বিদ্যুৎ'], ['amount' => 1200, 'method' => 'cash', 'date' => now()->toDateString()]);

        Purchase::firstOrCreate(['shop_id' => $shop->id, 'supplier' => 'তীর ডিস্ট্রিবিউটর', 'memo' => 'তেল+চিনি স্টক'], ['amount' => 12600, 'method' => 'credit', 'date' => now()->subDays(2)->toDateString()]);

        Damage::firstOrCreate(['shop_id' => $shop->id, 'product_id' => $productModels[3]->id, 'reason' => 'মেয়াদ শেষ'], ['qty' => 2, 'loss' => 48, 'date' => now()->subDay()->toDateString()]);

        Tenancy::clear();
    }

    /** A lighter demo shop per remaining business type, to show type-specific defaults & fields. */
    private function lightDemo(string $typeSlug, string $shopName, string $phone, string $area): void
    {
        $type = BusinessType::where('slug', $typeSlug)->first();
        if (! $type) {
            return;
        }

        $shop = $this->provisionOrReuse($phone, [
            'business_type_id' => $type->id,
            'name' => $shopName,
            'name_en' => $shopName,
            'phone' => $phone,
            'area' => $area,
            'owner_name' => $shopName.' Owner',
            'sales_mode' => 'both',
            'lang' => 'bn',
            'plan' => 'trial',
            'status' => 'active',
            'staff_limit' => 10, // demo shops showcase the full app, not the Business-tier default cap
            'subscription_start' => now()->toDateString(),
            'subscription_expiry' => now()->addDays(14)->toDateString(),
            'cash_balance' => 5000,
            'bank_balance' => 0,
            'capital' => 20000,
        ], $shopName.' Owner',
            // deliberately different per business type — a pharmacy and a
            // grocery shop don't need the same admin-granted capabilities,
            // and this is meant to demo that tiering, not just switch
            // everything on everywhere
            match ($typeSlug) {
                'pharmacy' => ['memo_whatsapp', 'memo_print', 'unit_conversion', 'purchases', 'damages', 'stock_count', 'accounts', 'expenses', 'reports', 'vat', 'cashier_management'], // strip -> tablet breakdown, strict expiry/stock discipline
                'mobile' => ['memo_whatsapp', 'memo_print', 'barcode_printing', 'purchases', 'returns', 'accounts', 'expenses', 'reports', 'cashier_management'], // IMEI/barcode scanning, warranty returns
                'clothing' => ['memo_whatsapp', 'memo_print', 'product_variants', 'purchases', 'returns', 'accounts', 'expenses', 'reports', 'cashier_management'], // color/size variant picker
                'supershop' => Feature::pluck('key')->all(), // biggest format, needs the full toolkit
                default => ['memo_whatsapp', 'memo_print', 'purchases', 'returns', 'accounts', 'expenses', 'reports', 'cashier_management'],
            }
        );

        Tenancy::set($shop->id); // see the comment in groceryFlagship() above

        $cat = ProductCategory::where('shop_id', $shop->id)->first();
        $unit = Unit::where('shop_id', $shop->id)->first();

        if ($typeSlug === 'clothing') {
            $this->clothingDemo($shop, $cat, $unit);
        } else {
            $extra = match ($typeSlug) {
                'pharmacy' => ['expiry_date' => now()->addYear()->toDateString(), 'batch_no' => 'BATCH-001'],
                'mobile' => ['imei' => '359123456789012'],
                'cosmetics' => ['expiry_date' => now()->addMonths(18)->toDateString()],
                default => [],
            };

            Product::firstOrCreate(
                ['shop_id' => $shop->id, 'name' => 'ডেমো পণ্য ১'],
                array_merge([
                    'category_id' => $cat?->id,
                    'unit_id' => $unit?->id,
                    'name_en' => 'Demo Product 1',
                    'emoji' => '📦',
                    'barcode' => '9000000000'.$shop->id,
                    'cost' => 100,
                    'price' => 150,
                    'stock' => 20,
                ], $extra)
            );
        }

        // pharmacy: a box of tablets, sellable as a whole strip or single tablets
        if ($typeSlug === 'pharmacy') {
            $tabletUnit = Unit::where('shop_id', $shop->id)->where('code', 'pcs')->first();
            $stripUnit = Unit::where('shop_id', $shop->id)->where('code', 'strip')->first();

            $medicine = Product::firstOrCreate(
                ['shop_id' => $shop->id, 'name' => 'নাপা ট্যাবলেট (১০০ পিস বক্স)'],
                [
                    'category_id' => $cat?->id,
                    'unit_id' => $tabletUnit?->id,
                    'name_en' => 'Napa Tablet (box of 100)',
                    'emoji' => '💊',
                    'barcode' => '9100000000'.$shop->id,
                    'cost' => 1.2,
                    'price' => 2, // per tablet
                    'stock' => 100,
                    'expiry_date' => now()->addYear()->toDateString(),
                    'batch_no' => 'NP-2026-01',
                ]
            );
            if ($stripUnit) {
                ProductUnit::firstOrCreate(
                    ['shop_id' => $shop->id, 'product_id' => $medicine->id, 'unit_id' => $stripUnit->id],
                    ['factor' => 10, 'price' => 18] // 1 strip = 10 tablets, whole-strip price
                );
            }
            // a product can carry more than one ProductUnit (hasMany, not a
            // single pack size) — this demonstrates the full box -> strip ->
            // tablet hierarchy Khaled specifically asked to see demoed
            // (e.g. Sergel: 1 box = 10 strips = 100 tablets), all factors
            // expressed directly in base (tablet) units so there's no
            // compounding rounding between levels
            $boxUnit = Unit::firstOrCreate(['shop_id' => $shop->id, 'name' => 'বক্স', 'code' => null], ['name_en' => 'Box']);
            ProductUnit::firstOrCreate(
                ['shop_id' => $shop->id, 'product_id' => $medicine->id, 'unit_id' => $boxUnit->id],
                ['factor' => 100, 'price' => 170] // 1 box = 10 strips x 10 tablets, whole-box price
            );
        }

        Customer::firstOrCreate(['shop_id' => $shop->id, 'phone' => '01800000000'], ['name' => 'Demo Customer', 'due' => 200, 'total_spent' => 1500, 'visits' => 3]);

        Tenancy::clear();
    }

    /**
     * Fashion Point's actual point is showing off the color/size variant
     * picker end-to-end (category -> product -> pick color -> pick size ->
     * that exact variant lands in the cart) — a flat size/color pair on the
     * base Product row (what this used to seed) never exercises that flow
     * at all, since ProductVariantController/Clothing/Pos.vue only offer a
     * picker when a product actually has variant rows.
     */
    private function clothingDemo(Shop $shop, ?ProductCategory $cat, ?Unit $unit): void
    {
        $shirt = Product::firstOrCreate(
            ['shop_id' => $shop->id, 'name' => 'কটন শার্ট'],
            [
                'category_id' => $cat?->id,
                'unit_id' => $unit?->id,
                'name_en' => 'Cotton Shirt',
                'emoji' => '👕',
                'cost' => 400,
                'price' => 650,
                'stock' => 0, // variant products keep their own stock sum on the parent — see ProductVariantController
            ]
        );

        $variants = [
            ['color' => 'লাল', 'size' => 'M', 'stock' => 12],
            ['color' => 'লাল', 'size' => 'L', 'stock' => 8],
            ['color' => 'নীল', 'size' => 'M', 'stock' => 10],
            ['color' => 'নীল', 'size' => 'L', 'stock' => 6],
        ];
        foreach ($variants as $i => $v) {
            $shirt->variants()->firstOrCreate(
                ['shop_id' => $shop->id, 'color' => $v['color'], 'size' => $v['size']],
                ['barcode' => '9200000000'.$shop->id.$i, 'stock' => $v['stock'], 'price' => null, 'cost' => null]
            );
        }
        // keep the parent's displayed stock in sync with what was just
        // seeded, matching what ProductVariantController does after every
        // real variant change
        $shirt->update(['stock' => $shirt->variants()->sum('stock')]);

        Product::firstOrCreate(
            ['shop_id' => $shop->id, 'name' => 'সুতি শাড়ি'],
            [
                'category_id' => $cat?->id,
                'unit_id' => $unit?->id,
                'name_en' => 'Cotton Saree',
                'emoji' => '🥻',
                'cost' => 900,
                'price' => 1500,
                'stock' => 15,
            ]
        );
    }
}
