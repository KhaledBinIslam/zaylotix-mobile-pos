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

        $cats = ProductCategory::where('shop_id', $shop->id)->get()->keyBy('name_en');
        $unit = Unit::where('shop_id', $shop->id)->first();

        // Showcases all 3 of Product::STOCK_MODE_* — a real restaurant menu
        // is a mix, not one mode for everything: biryani is cooked to order
        // (no meaningful count), kabab is the kind of dish an owner might
        // just flip to "sold out" once the tray's empty, and bottled/canned
        // drinks are exactly the packaged-goods case a real piece count
        // still makes sense for. Spread across every menu category (not
        // just the first one) so a seller demo shows all of them populated.
        $menu = [
            ['name' => 'চিকেন বিরিয়ানি', 'name_en' => 'Chicken Biryani', 'emoji' => '🍛', 'cat' => 'Rice & Curry', 'price' => 220, 'cost' => 140, 'stock_mode' => Product::STOCK_MODE_UNTRACKED, 'stock' => 0],
            ['name' => 'খিচুড়ি', 'name_en' => 'Khichuri', 'emoji' => '🍛', 'cat' => 'Rice & Curry', 'price' => 90, 'cost' => 55, 'stock_mode' => Product::STOCK_MODE_UNTRACKED, 'stock' => 0],
            ['name' => 'বিফ কাবাব', 'name_en' => 'Beef Kabab', 'emoji' => '🍢', 'cat' => 'Kebab/Grill', 'price' => 180, 'cost' => 110, 'stock_mode' => Product::STOCK_MODE_TOGGLE, 'stock' => 1],
            ['name' => 'চিকেন গ্রিল', 'name_en' => 'Chicken Grill', 'emoji' => '🍗', 'cat' => 'Kebab/Grill', 'price' => 200, 'cost' => 130, 'stock_mode' => Product::STOCK_MODE_TOGGLE, 'stock' => 1],
            ['name' => 'চিকেন বার্গার', 'name_en' => 'Chicken Burger', 'emoji' => '🍔', 'cat' => 'Fast Food', 'price' => 150, 'cost' => 95, 'stock_mode' => Product::STOCK_MODE_TOGGLE, 'stock' => 1],
            ['name' => 'ফ্রেঞ্চ ফ্রাই', 'name_en' => 'French Fries', 'emoji' => '🍟', 'cat' => 'Fast Food', 'price' => 100, 'cost' => 60, 'stock_mode' => Product::STOCK_MODE_TRACKED, 'stock' => 30],
            ['name' => 'কোল্ড ড্রিংকস', 'name_en' => 'Cold Drinks', 'emoji' => '🥤', 'cat' => 'Drinks', 'price' => 40, 'cost' => 25, 'stock_mode' => Product::STOCK_MODE_TRACKED, 'stock' => 48],
            ['name' => 'বোরহানি', 'name_en' => 'Borhani', 'emoji' => '🥛', 'cat' => 'Drinks', 'price' => 30, 'cost' => 18, 'stock_mode' => Product::STOCK_MODE_TRACKED, 'stock' => 20],
            ['name' => 'ফিরনি', 'name_en' => 'Firni', 'emoji' => '🍮', 'cat' => 'Sweets/Dessert', 'price' => 50, 'cost' => 30, 'stock_mode' => Product::STOCK_MODE_TRACKED, 'stock' => 15],
            ['name' => 'মিষ্টি দই', 'name_en' => 'Sweet Yogurt', 'emoji' => '🍮', 'cat' => 'Sweets/Dessert', 'price' => 40, 'cost' => 25, 'stock_mode' => Product::STOCK_MODE_TRACKED, 'stock' => 18],
            ['name' => 'সালাদ', 'name_en' => 'Salad', 'emoji' => '🥗', 'cat' => 'Other', 'price' => 30, 'cost' => 15, 'stock_mode' => Product::STOCK_MODE_UNTRACKED, 'stock' => 0],
        ];
        foreach ($menu as $m) {
            Product::firstOrCreate(
                ['shop_id' => $shop->id, 'name' => $m['name']],
                [
                    'category_id' => $cats[$m['cat']]->id ?? null,
                    'unit_id' => $unit?->id,
                    'name_en' => $m['name_en'],
                    'emoji' => $m['emoji'],
                    'cost' => $m['cost'],
                    'price' => $m['price'],
                    'stock_mode' => $m['stock_mode'],
                    'stock' => $m['stock'],
                ]
            );
        }

        // an earlier version of this seeder mis-categorized every menu item
        // into whichever category happened to be first() — fix that up on
        // shops that already exist, so this isn't just a fresh-install fix.
        foreach ($menu as $m) {
            if (isset($cats[$m['cat']])) {
                Product::where('shop_id', $shop->id)->where('name', $m['name'])->update(['category_id' => $cats[$m['cat']]->id]);
            }
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
            // lightDemo() below for an example of that tiering).
            // restaurant_tables excluded deliberately: unlike every other
            // feature here (purely additive capability), it also controls
            // which entire "Sell" screen/flow this shop routes to (see
            // Shop::isRestaurant()) — granting it to a non-restaurant shop
            // caused exactly this account (grocery) to land on the Table
            // order screen instead of the normal POS, breaking "add to cart"
            // for it entirely. Routing itself no longer trusts this flag
            // alone, but a grocery shop still shouldn't carry a restaurant-
            // only feature it can never meaningfully use.
        ], 'Khaled Bin Islam', Feature::where('key', '!=', 'restaurant_tables')->pluck('key')->all());

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
            // Dairy and Other were previously empty — every nav category
            // should have something in it for a seller demo to look complete.
            ['name' => 'ডিম (ডজন)', 'name_en' => 'Egg (dozen)', 'emoji' => '🥚', 'cat' => 'Dairy', 'price' => 150, 'cost' => 135, 'stock' => 20, 'barcode' => '8901234500116'],
            ['name' => 'ব্র্যাক দই ৫০০গ্রাম', 'name_en' => 'Brac Yogurt 500g', 'emoji' => '🍶', 'cat' => 'Dairy', 'price' => 90, 'cost' => 75, 'stock' => 15, 'barcode' => '8901234500123'],
            ['name' => 'ম্যাচ বক্স', 'name_en' => 'Match Box', 'emoji' => '🔥', 'cat' => 'Other', 'price' => 5, 'cost' => 3, 'stock' => 100, 'barcode' => '8901234500130'],
            ['name' => 'টিস্যু পেপার', 'name_en' => 'Tissue Paper', 'emoji' => '🧻', 'cat' => 'Other', 'price' => 60, 'cost' => 48, 'stock' => 25, 'barcode' => '8901234500147'],
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
                // biggest format, needs the full toolkit — restaurant_tables
                // excluded, see the matching comment in groceryFlagship() above
                'supershop' => Feature::where('key', '!=', 'restaurant_tables')->pluck('key')->all(),
                default => ['memo_whatsapp', 'memo_print', 'purchases', 'returns', 'accounts', 'expenses', 'reports', 'cashier_management'],
            }
        );

        Tenancy::set($shop->id); // see the comment in groceryFlagship() above

        $cats = ProductCategory::where('shop_id', $shop->id)->get()->keyBy('name_en');
        $cat = $cats->first();
        $unit = Unit::where('shop_id', $shop->id)->first();

        if ($typeSlug === 'clothing') {
            $this->clothingDemo($shop, $cats, $unit);
        } else {
            // remove the old single placeholder product from shops that
            // already have it — categorizedCatalog() below now seeds a
            // real, properly-categorized product in its place.
            Product::where('shop_id', $shop->id)->where('name', 'ডেমো পণ্য ১')->delete();
            $this->seedCatalog($shop, $typeSlug, $cats, $unit);
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
     *
     * $cats is keyed by name_en (see lightDemo) — both the shirt and the
     * saree used to be dropped into whichever category was first() instead
     * of their real one; fixed here so every clothing category (Shirts,
     * Pants, Saree, Three-piece, Kids wear, Shoes, Other) actually has a
     * product in it for a seller demo.
     */
    private function clothingDemo(Shop $shop, \Illuminate\Support\Collection $cats, ?Unit $unit): void
    {
        $shirt = Product::firstOrCreate(
            ['shop_id' => $shop->id, 'name' => 'কটন শার্ট'],
            [
                'category_id' => $cats['Shirts']->id ?? null,
                'unit_id' => $unit?->id,
                'name_en' => 'Cotton Shirt',
                'emoji' => '👕',
                'cost' => 400,
                'price' => 650,
                'stock' => 0, // variant products keep their own stock sum on the parent — see ProductVariantController
            ]
        );
        Product::where('id', $shirt->id)->update(['category_id' => $cats['Shirts']->id ?? null]);

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

        $others = [
            ['name' => 'ফরমাল প্যান্ট', 'name_en' => 'Formal Pant', 'emoji' => '👖', 'cat' => 'Pants', 'price' => 850, 'cost' => 550, 'stock' => 18],
            ['name' => 'জিন্স প্যান্ট', 'name_en' => 'Jeans', 'emoji' => '👖', 'cat' => 'Pants', 'price' => 1100, 'cost' => 750, 'stock' => 14],
            ['name' => 'সুতি শাড়ি', 'name_en' => 'Cotton Saree', 'emoji' => '🥻', 'cat' => 'Saree', 'price' => 1500, 'cost' => 900, 'stock' => 15],
            ['name' => 'জামদানি শাড়ি', 'name_en' => 'Jamdani Saree', 'emoji' => '🥻', 'cat' => 'Saree', 'price' => 4500, 'cost' => 3200, 'stock' => 5],
            ['name' => 'থ্রি-পিস (কাতান)', 'name_en' => 'Three-piece (Katan)', 'emoji' => '👗', 'cat' => 'Three-piece', 'price' => 2200, 'cost' => 1600, 'stock' => 10],
            ['name' => 'বাচ্চাদের ফ্রক', 'name_en' => 'Kids Frock', 'emoji' => '🧒', 'cat' => 'Kids wear', 'price' => 550, 'cost' => 350, 'stock' => 12],
            ['name' => 'স্নিকার্স জুতা', 'name_en' => 'Sneakers', 'emoji' => '👟', 'cat' => 'Shoes', 'price' => 1800, 'cost' => 1300, 'stock' => 8],
            ['name' => 'বেল্ট', 'name_en' => 'Belt', 'emoji' => '📦', 'cat' => 'Other', 'price' => 350, 'cost' => 220, 'stock' => 20],
        ];
        foreach ($others as $p) {
            Product::firstOrCreate(
                ['shop_id' => $shop->id, 'name' => $p['name']],
                [
                    'category_id' => $cats[$p['cat']]->id ?? null,
                    'unit_id' => $unit?->id,
                    'name_en' => $p['name_en'],
                    'emoji' => $p['emoji'],
                    'cost' => $p['cost'],
                    'price' => $p['price'],
                    'stock' => $p['stock'],
                ]
            );
        }

        // fix up the saree's category on shops seeded before this method
        // spread products across the real categories (it used to land in
        // Shirts, same bug as the shirt above).
        if (isset($cats['Saree'])) {
            Product::where('shop_id', $shop->id)->where('name', 'সুতি শাড়ি')->update(['category_id' => $cats['Saree']->id]);
        }
    }

    /**
     * Seeds a handful of realistic products into every category of a
     * business type's catalog (see categorizedCatalog() below) so a demo
     * shop shows something in *every* nav category — a seller being trained
     * on their own shop type needs to see it populated, not just whichever
     * category lightDemo's old single placeholder product happened to land
     * in (always the first one, see the bug this replaced).
     */
    private function seedCatalog(Shop $shop, string $typeSlug, \Illuminate\Support\Collection $cats, ?Unit $unit): void
    {
        foreach ($this->categorizedCatalog($typeSlug) as $catName => $items) {
            foreach ($items as $p) {
                Product::firstOrCreate(
                    ['shop_id' => $shop->id, 'name' => $p['name']],
                    array_merge([
                        'category_id' => $cats[$catName]->id ?? null,
                        'unit_id' => $unit?->id,
                        'name_en' => $p['name_en'],
                        'emoji' => $p['emoji'],
                        'cost' => $p['cost'],
                        'price' => $p['price'],
                        'stock' => $p['stock'],
                    ], $p['extra'] ?? [])
                );
            }
        }
    }

    /** Category-keyed (by name_en) product catalog per business type, used by seedCatalog(). */
    private function categorizedCatalog(string $typeSlug): array
    {
        return match ($typeSlug) {
            // 'Tablets' is deliberately absent here — the Napa Tablet
            // box->strip->tablet unit-conversion demo (built separately in
            // lightDemo) already covers that category on its own.
            'pharmacy' => [
                'Syrup' => [
                    ['name' => 'এইস প্লাস সিরাপ ৬০মিলি', 'name_en' => 'Ace Plus Syrup 60ml', 'emoji' => '🍯', 'price' => 45, 'cost' => 35, 'stock' => 30, 'extra' => ['expiry_date' => now()->addMonths(20)->toDateString(), 'batch_no' => 'SYR-2026-01']],
                    ['name' => 'ফিলসন সিরাপ ১০০মিলি', 'name_en' => 'Filson Syrup 100ml', 'emoji' => '🍯', 'price' => 60, 'cost' => 48, 'stock' => 25, 'extra' => ['expiry_date' => now()->addMonths(20)->toDateString(), 'batch_no' => 'SYR-2026-02']],
                ],
                'Injection' => [
                    ['name' => 'ভিটামিন বি১২ ইনজেকশন', 'name_en' => 'Vitamin B12 Injection', 'emoji' => '💉', 'price' => 25, 'cost' => 18, 'stock' => 40, 'extra' => ['expiry_date' => now()->addMonths(15)->toDateString(), 'batch_no' => 'INJ-2026-01']],
                    ['name' => 'ডেক্সট্রোজ স্যালাইন', 'name_en' => 'Dextrose Saline', 'emoji' => '💉', 'price' => 90, 'cost' => 70, 'stock' => 20, 'extra' => ['expiry_date' => now()->addMonths(15)->toDateString(), 'batch_no' => 'INJ-2026-02']],
                ],
                'Medical equipment' => [
                    ['name' => 'ডিজিটাল থার্মোমিটার', 'name_en' => 'Digital Thermometer', 'emoji' => '🩺', 'price' => 250, 'cost' => 180, 'stock' => 10],
                    ['name' => 'ব্লাড প্রেসার মেশিন', 'name_en' => 'Blood Pressure Machine', 'emoji' => '🩺', 'price' => 1800, 'cost' => 1400, 'stock' => 5],
                ],
                'Other' => [
                    ['name' => 'সার্জিক্যাল হ্যান্ড গ্লাভস', 'name_en' => 'Surgical Hand Gloves', 'emoji' => '🧤', 'price' => 10, 'cost' => 6, 'stock' => 100],
                    ['name' => 'ফেস মাস্ক (বক্স)', 'name_en' => 'Face Mask (box)', 'emoji' => '😷', 'price' => 120, 'cost' => 90, 'stock' => 15],
                ],
            ],
            'mobile' => [
                'Handsets' => [
                    ['name' => 'স্যামসাং গ্যালাক্সি A15', 'name_en' => 'Samsung Galaxy A15', 'emoji' => '📱', 'price' => 19500, 'cost' => 17800, 'stock' => 4, 'extra' => ['imei' => '359123456789012']],
                    ['name' => 'শাওমি রেডমি ১৩সি', 'name_en' => 'Xiaomi Redmi 13C', 'emoji' => '📱', 'price' => 13500, 'cost' => 12200, 'stock' => 6, 'extra' => ['imei' => '359123456789099']],
                ],
                'Chargers' => [
                    ['name' => 'স্যামসাং ফাস্ট চার্জার', 'name_en' => 'Samsung Fast Charger', 'emoji' => '🔌', 'price' => 550, 'cost' => 420, 'stock' => 20],
                    ['name' => 'টাইপ-সি ক্যাবল', 'name_en' => 'Type-C Cable', 'emoji' => '🔌', 'price' => 150, 'cost' => 100, 'stock' => 35],
                ],
                'Headphones' => [
                    ['name' => 'ওয়্যারলেস ইয়ারবাড', 'name_en' => 'Wireless Earbuds', 'emoji' => '🎧', 'price' => 1200, 'cost' => 900, 'stock' => 12],
                ],
                'Covers' => [
                    ['name' => 'সিলিকন ব্যাক কভার', 'name_en' => 'Silicone Back Cover', 'emoji' => '🛡️', 'price' => 180, 'cost' => 120, 'stock' => 30],
                ],
                'Accessories' => [
                    ['name' => 'পাওয়ার ব্যাংক ১০০০০mAh', 'name_en' => 'Power Bank 10000mAh', 'emoji' => '🔋', 'price' => 1400, 'cost' => 1100, 'stock' => 8],
                    ['name' => 'মেমোরি কার্ড ৩২জিবি', 'name_en' => 'Memory Card 32GB', 'emoji' => '💾', 'price' => 450, 'cost' => 350, 'stock' => 18],
                ],
                'Other' => [
                    ['name' => 'স্ক্রিন প্রোটেক্টর', 'name_en' => 'Screen Protector', 'emoji' => '📱', 'price' => 100, 'cost' => 60, 'stock' => 40],
                ],
            ],
            'cosmetics' => [
                'Skin care' => [
                    ["name" => 'পন্ডস ফেসওয়াশ', 'name_en' => "Pond's Face Wash", 'emoji' => '🧴', 'price' => 220, 'cost' => 175, 'stock' => 25, 'extra' => ['expiry_date' => now()->addMonths(18)->toDateString()]],
                ],
                'Makeup' => [
                    ['name' => 'ম্যাক লিপস্টিক', 'name_en' => 'MAC Lipstick', 'emoji' => '💄', 'price' => 850, 'cost' => 650, 'stock' => 10, 'extra' => ['expiry_date' => now()->addMonths(18)->toDateString()]],
                    ['name' => 'কম্প্যাক্ট পাউডার', 'name_en' => 'Compact Powder', 'emoji' => '💄', 'price' => 380, 'cost' => 290, 'stock' => 14, 'extra' => ['expiry_date' => now()->addMonths(18)->toDateString()]],
                ],
                'Perfume' => [
                    ['name' => 'জারা পারফিউম ১০০মিলি', 'name_en' => 'Zara Perfume 100ml', 'emoji' => '🧪', 'price' => 1600, 'cost' => 1250, 'stock' => 6, 'extra' => ['expiry_date' => now()->addMonths(18)->toDateString()]],
                ],
                'Hair care' => [
                    ['name' => 'সানসিল্ক শ্যাম্পু', 'name_en' => 'Sunsilk Shampoo', 'emoji' => '💇', 'price' => 320, 'cost' => 260, 'stock' => 20, 'extra' => ['expiry_date' => now()->addMonths(18)->toDateString()]],
                    ['name' => 'প্যারাসুট নারিকেল তেল', 'name_en' => 'Parachute Coconut Oil', 'emoji' => '💇', 'price' => 180, 'cost' => 140, 'stock' => 22, 'extra' => ['expiry_date' => now()->addMonths(18)->toDateString()]],
                ],
                'Other' => [
                    ['name' => 'নেইল পলিশ', 'name_en' => 'Nail Polish', 'emoji' => '💅', 'price' => 150, 'cost' => 100, 'stock' => 16, 'extra' => ['expiry_date' => now()->addMonths(18)->toDateString()]],
                ],
            ],
            'supershop' => [
                'Grocery' => [
                    ['name' => 'স্কয়ার লবণ ১ কেজি', 'name_en' => 'Square Salt 1kg', 'emoji' => '🧂', 'price' => 40, 'cost' => 34, 'stock' => 60],
                    ['name' => 'ফ্রেশ চা পাতা ২০০গ্রাম', 'name_en' => 'Fresh Tea 200g', 'emoji' => '🍵', 'price' => 160, 'cost' => 140, 'stock' => 30],
                ],
                'Oil & Ghee' => [
                    ['name' => 'রূপচাঁদা সয়াবিন তেল ৫ লিটার', 'name_en' => 'Rupchanda Soybean Oil 5L', 'emoji' => '🫒', 'price' => 920, 'cost' => 860, 'stock' => 20],
                ],
                'Rice & Flour' => [
                    ['name' => 'মিনিকেট চাল ৫০ কেজি', 'name_en' => 'Miniket Rice 50kg', 'emoji' => '🍚', 'price' => 3200, 'cost' => 3050, 'stock' => 8],
                ],
                'Spices' => [
                    ['name' => 'মরিচ গুড়া ২০০গ্রাম', 'name_en' => 'Chili Powder 200g', 'emoji' => '🌶️', 'price' => 70, 'cost' => 55, 'stock' => 25],
                ],
                'Drinks' => [
                    ['name' => 'কোকাকোলা ১ লিটার', 'name_en' => 'Coca-Cola 1L', 'emoji' => '🥤', 'price' => 90, 'cost' => 75, 'stock' => 40],
                    ['name' => 'মোজো ৫০০মিলি', 'name_en' => 'Mojo 500ml', 'emoji' => '🥤', 'price' => 40, 'cost' => 32, 'stock' => 50],
                ],
                'Snacks' => [
                    ['name' => 'প্রিংগলস চিপস', 'name_en' => 'Pringles Chips', 'emoji' => '🍪', 'price' => 250, 'cost' => 210, 'stock' => 15],
                ],
                'Dairy' => [
                    ['name' => 'মার্কস গুড়া দুধ ৫০০গ্রাম', 'name_en' => 'Marks Milk Powder 500g', 'emoji' => '🥛', 'price' => 480, 'cost' => 440, 'stock' => 10],
                ],
                'Personal care' => [
                    ['name' => 'লাক্স সাবান', 'name_en' => 'Lux Soap', 'emoji' => '🧼', 'price' => 45, 'cost' => 38, 'stock' => 60],
                    ['name' => 'কোলগেট টুথপেস্ট', 'name_en' => 'Colgate Toothpaste', 'emoji' => '🪥', 'price' => 90, 'cost' => 75, 'stock' => 40],
                ],
                'Other' => [
                    ['name' => 'প্লাস্টিক ব্যাগ (প্যাকেট)', 'name_en' => 'Plastic Bag (pack)', 'emoji' => '🛍️', 'price' => 20, 'cost' => 12, 'stock' => 100],
                ],
            ],
            'general' => [
                'General' => [
                    ['name' => 'নোটবুক (৮০ পাতা)', 'name_en' => 'Notebook (80 pages)', 'emoji' => '📓', 'price' => 40, 'cost' => 28, 'stock' => 50],
                    ['name' => 'বলপয়েন্ট কলম', 'name_en' => 'Ballpoint Pen', 'emoji' => '🖊️', 'price' => 10, 'cost' => 6, 'stock' => 100],
                    ['name' => 'প্লাস্টিক বালতি', 'name_en' => 'Plastic Bucket', 'emoji' => '🪣', 'price' => 180, 'cost' => 140, 'stock' => 15],
                ],
                'Other' => [
                    ['name' => 'ছাতা', 'name_en' => 'Umbrella', 'emoji' => '☂️', 'price' => 350, 'cost' => 260, 'stock' => 10],
                    ['name' => 'টর্চ লাইট', 'name_en' => 'Torch Light', 'emoji' => '🔦', 'price' => 150, 'cost' => 100, 'stock' => 12],
                ],
            ],
            default => [],
        };
    }
}
