<?php

namespace App\Http\Controllers\App;

use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\ProductVariant;
use App\Models\Shop;
use App\Models\Supplier;
use App\Models\Unit;
use App\Support\Activity;
use App\Support\ArticleBarcode;
use App\Support\ArticleSerial;
use App\Support\CatalogSync;
use App\Support\ColorBarcodeResolver;
use App\Support\SizeGroupPricing;
use App\Support\Tenancy;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;

/**
 * docs/ARTICLE_SERIES_PLAN.md §7 — one-screen new article entry: manufacturer
 * + category (both reuse-or-create, same as ProductController::store()) +
 * cost (encoded into the barcode's cost-code) + a color x size grid, all in
 * one submit. Gated behind the `article_series` feature flag — opt-in,
 * zero behavior change for every shop that doesn't have it.
 *
 * "বাচ্চাদের জুতা" (Kids' Shoes) articles get 3 price boxes
 * (Small/Medium/Large) instead of one — see SizeGroupPricing's own
 * docblock for why no ProductVariant schema change was needed for this.
 */
class ArticleController extends Controller
{
    /** Category names (English/Bengali) that trigger the 3-price-box kids'-shoe flow — exactly the names config/business_types.php seeds, same synonym source ProductsImportArticles::CATEGORY_SYNONYMS already uses for 'KIDS'. */
    private const KIDS_CATEGORY_NAMES = ["Kids' Shoes", 'বাচ্চাদের জুতা'];

    public function create()
    {
        $shop = Tenancy::shop();

        return Inertia::render('App/Articles/Create', [
            'suppliers' => Supplier::orderBy('name')->get(['id', 'name', 'short_code']),
            'categories' => ProductCategory::orderBy('name')->get(['id', 'name', 'name_en', 'code']),
            'units' => Unit::orderBy('name')->get(['id', 'name', 'name_en']),
            'colorCodeMap' => $shop->colorCodeMap(),
            // lets the form show the cost-code segment of the barcode as
            // soon as a cost is typed, instead of a placeholder that only
            // resolved after saving (see support/costCode.js — display
            // only, the saved barcode is still built here on submit)
            'costCodeDigitMap' => $shop->costCodeDigitMap(),
            'kidsShoeSizeGroups' => $shop->kidsShoeSizeGroups(),
            // The form decides whether to draw one price box or one per
            // size group, and it has to reach the SAME verdict as
            // isKidsCategory() does on submit. It used to be sent only the
            // names, so Baby Park — whose category is called "BABY" — never
            // got the group boxes no matter what was picked in settings.
            'sizeGroupCategoryCodes' => $shop->sizeGroupCategoryCodes(),
            'kidsCategoryNames' => self::KIDS_CATEGORY_NAMES,
        ]);
    }

    /** Read-only live preview of what the next serial for this category WOULD be — never reserves it (ArticleSerial::peekNext()'s own guarantee). */
    public function peekSerial(Request $request)
    {
        $shop = Tenancy::shop();
        $data = $request->validate([
            'category_id' => ['nullable', 'integer'],
            'category_code' => ['nullable', 'string', 'max:5'],
        ]);

        $categoryCode = null;
        if (! empty($data['category_id'])) {
            $categoryCode = ProductCategory::find($data['category_id'])?->code;
        }
        $categoryCode ??= ! empty($data['category_code']) ? strtoupper($data['category_code']) : null;

        if (! $categoryCode) {
            return response()->json(['serial' => null]);
        }

        return response()->json(['serial' => $this->nextFreeSerial($shop, $categoryCode)]);
    }

    /**
     * What to prefill the form's serial with: the lowest number this
     * category has NOT got a live article for.
     *
     * The counter alone only ever moves forward, so deleting B-20007 and
     * B-20008 left the next article starting at 20009 with two numbers
     * stranded. Khaled asked for those to come back. A gap only exists
     * where an article was deleted, and the serial field is editable
     * anyway, so this is a suggestion rather than a rule — store()'s own
     * check is what guarantees two live articles never share one.
     *
     * The walk starts at this category's lowest existing serial, not at
     * ArticleSerial::START: imported categories began at 20001, and
     * starting from 10001 would suggest a number from a range this shop
     * has never used.
     */
    private function nextFreeSerial(Shop $shop, string $categoryCode): int
    {
        $counterNext = ArticleSerial::peekNext($shop, $categoryCode);

        $used = Product::withoutGlobalScopes()
            ->whereNull('deleted_at')
            ->whereIn('shop_id', Tenancy::businessShopIds($shop->id))
            ->where('name', 'like', $categoryCode.'-%')
            ->pluck('name')
            ->map(fn ($name) => (int) substr((string) $name, strlen($categoryCode) + 1))
            ->filter(fn ($serial) => $serial > 0)
            ->unique()->sort()->values();

        if ($used->isEmpty()) {
            return $counterNext;
        }

        $candidate = $used->first();
        foreach ($used as $serial) {
            if ($serial === $candidate) {
                $candidate++;
            } elseif ($serial > $candidate) {
                break;
            }
        }

        return $candidate;
    }

    public function store(Request $request)
    {
        $shop = Tenancy::shop();

        $data = $request->validate([
            'client_uuid' => ['required', 'string', 'max:64'],
            'category_id' => ['nullable', 'integer'],
            'new_category_name' => ['nullable', 'string', 'max:255'],
            'category_code' => ['nullable', 'string', 'max:5'],
            'manufacturer_code' => ['required', 'string', 'max:20'],
            'unit_id' => ['nullable', 'integer'],
            'new_unit_name' => ['nullable', 'string', 'max:50'],
            'cost' => ['required', 'integer', 'min:0'],
            'price' => ['nullable', 'numeric', 'min:0'],
            'kids_prices' => ['nullable', 'array'],
            'kids_prices.Small' => ['nullable', 'numeric', 'min:0'],
            'kids_prices.Medium' => ['nullable', 'numeric', 'min:0'],
            'kids_prices.Large' => ['nullable', 'numeric', 'min:0'],
            'variants' => ['required', 'array', 'min:1'],
            'variants.*.color' => ['nullable', 'string', 'max:50'],
            'variants.*.size' => ['required', 'string', 'max:50'],
            'variants.*.qty' => ['required', 'integer', 'min:0'],
            // SkyBuy-style picker (docs/ARTICLE_SERIES_PLAN.md §4) — one
            // optional photo per color, keyed by color name; never required,
            // the picker falls back to a plain color swatch without one
            'photos' => ['nullable', 'array'],
            'photos.*' => ['nullable', 'image', 'max:3072'],
            // Khaled: a serial freed by deleting an article should be usable
            // again, and a wrong one should be fixable. Absent = take the
            // next one from the counter, exactly as before.
            'serial' => ['nullable', 'integer', 'min:1'],
        ]);

        // idempotent resubmit (double-tap, flaky connection retry): the
        // first successful submit for this client_uuid already created the
        // article — hand back the same result instead of creating a second one
        $cacheKey = "article:submitted:{$shop->id}:{$data['client_uuid']}";
        if (Cache::has($cacheKey)) {
            $cached = Cache::get($cacheKey);

            return back()->with('success', "আর্টিকেল {$cached['name']} যোগ হয়েছে।")
                ->with('productId', $cached['product_id'])->with('productBarcode', $cached['barcode']);
        }

        $category = $this->resolveCategory($data);
        if ($category instanceof \Illuminate\Http\RedirectResponse) {
            return $category;
        }

        $supplier = $this->resolveSupplier($data['manufacturer_code']);
        $unitId = $this->resolveUnit($data);

        $costCode = ArticleBarcode::encodeCost($shop, (int) $data['cost']);
        if ($costCode === null) {
            return back()->withErrors(['cost' => 'এই দামের কিছু অঙ্কের জন্য এখনো অক্ষর ঠিক করা নেই — উপরের "⚙️ কস্ট-কোড সেটিংস" বোতামে গিয়ে বাকি অঙ্কগুলোর অক্ষর বসিয়ে আসুন, তারপর আবার চেষ্টা করুন।']);
        }

        $isKids = $this->isKidsCategory($category);
        $kidsPrices = array_filter($data['kids_prices'] ?? []);

        if ($isKids) {
            if (! $kidsPrices) {
                return back()->withErrors(['kids_prices' => 'বাচ্চাদের জুতার জন্য অন্তত একটা সাইজ-গ্রুপের (Small/Medium/Large) দাম দিতে হবে।']);
            }
            $basePrice = $kidsPrices['Medium'] ?? $kidsPrices['Small'] ?? $kidsPrices['Large'];
        } else {
            if (empty($data['price'])) {
                return back()->withErrors(['price' => 'দাম দিতে হবে।']);
            }
            $basePrice = $data['price'];
        }

        $variantRows = array_values(array_filter($data['variants'], fn ($v) => (int) $v['qty'] > 0));
        if (! $variantRows) {
            return back()->withErrors(['variants' => 'অন্তত একটা রং/সাইজে স্টক দিতে হবে।']);
        }

        // A size outside every configured group priced itself at the
        // article's base price — the Medium box — without a word. Baby Park
        // enter sizes 16-19 while the default groups start at 21, so a
        // whole article would have been stickered at the wrong price and
        // sold at it. Refusing beats guessing: a wrong number printed on a
        // sticker is money, and nothing downstream can tell it was a guess.
        if ($isKids) {
            $ungrouped = [];
            foreach ($variantRows as $row) {
                if (SizeGroupPricing::priceForSize($shop, $row['size'], $kidsPrices) === null) {
                    $ungrouped[(string) $row['size']] = true;
                }
            }
            if ($ungrouped) {
                $sizes = implode(', ', array_keys($ungrouped));

                return back()->withErrors(['kids_prices' => "সাইজ {$sizes} কোনো গ্রুপের রেঞ্জে পড়ছে না, বা ওই গ্রুপের দাম দেওয়া হয়নি — তাই এই সাইজগুলোর দাম ঠিক করা যাচ্ছে না। সাইজ-গ্রুপ সেটিংসে রেঞ্জ ঠিক করুন, অথবা উপরে ওই গ্রুপের দাম বসান।"]);
            }
        }

        // A typed serial may reuse one a deleted article left behind, but
        // never one a LIVE article still answers to — two articles sharing a
        // serial means two different shoes behind one barcode. Business-wide
        // because the counter itself is shared by a shop and its branches.
        if (isset($data['serial'])) {
            $taken = Product::withoutGlobalScopes()
                ->whereNull('deleted_at')
                ->whereIn('shop_id', Tenancy::businessShopIds($shop->id))
                ->where('name', "{$category->code}-{$data['serial']}")
                ->exists();
            if ($taken) {
                return back()->withErrors(['serial' => "{$category->code}-{$data['serial']} নামে একটা আর্টিকেল এখনো আছে — অন্য একটা নম্বর দিন, নয়তো আগেরটা আগে মুছুন।"]);
            }
        }

        $product = DB::transaction(function () use ($shop, $category, $supplier, $unitId, $data, $costCode, $isKids, $kidsPrices, $basePrice, $variantRows, $request) {
            // A typed serial is used as-is; the counter is then pushed past
            // it so the next automatic one can't land on it. Without one,
            // the counter issues the next as it always did.
            $serial = isset($data['serial'])
                ? (int) $data['serial']
                : ArticleSerial::next($shop, $category->code);
            if (isset($data['serial'])) {
                ArticleSerial::bumpFloor($shop, $category->code, $serial + 1);
            }

            $articleBarcode = ArticleBarcode::buildArticleBarcode($shop, strtoupper($data['manufacturer_code']), $category->code, $serial, $costCode);
            $name = "{$category->code}-{$serial}";

            $product = Product::create([
                'category_id' => $category->id,
                'supplier_id' => $supplier->id,
                'unit_id' => $unitId,
                'name' => $name,
                'name_en' => $name,
                'barcode' => $articleBarcode,
                'emoji' => '👟',
                'cost' => $data['cost'],
                'price' => $basePrice,
                'stock' => 0, // live-maintained sum of variants, same convention as ProductsImportArticles
            ]);

            $gridTotal = 0;
            foreach ($variantRows as $row) {
                $variantPrice = $isKids ? SizeGroupPricing::priceForSize($shop, $row['size'], $kidsPrices) : null;
                ProductVariant::create([
                    'product_id' => $product->id,
                    'size' => $row['size'],
                    'color' => $row['color'] ?? null,
                    'barcode' => ArticleBarcode::buildVariantBarcode($shop, $articleBarcode, $row['color'] ?? null, $row['size']),
                    'stock' => $row['qty'],
                    'price' => $variantPrice,
                ]);
                $gridTotal += (int) $row['qty'];
            }

            $product->stock = $gridTotal;
            $product->saveQuietly();

            // one photo per color, applied to every variant sharing it —
            // uploaded via `photos[COLOR]` alongside the color/size grid
            foreach (array_unique(array_filter(array_column($variantRows, 'color'))) as $color) {
                if ($request->hasFile("photos.{$color}")) {
                    $path = $request->file("photos.{$color}")->store('product-photos', 'public');
                    ProductVariant::where('product_id', $product->id)->where('color', $color)->update(['photo_path' => $path]);
                }
            }

            // docs/ARTICLE_SERIES_PLAN.md §9 — the scannable "tier 2" sticker barcode, one per color
            foreach (array_unique(array_filter(array_column($variantRows, 'color'))) as $color) {
                ColorBarcodeResolver::resolve($shop, $product, $color, $articleBarcode);
            }

            return $product;
        });

        if ($shop->hasFeature('shared_catalog') && ! $shop->parent_shop_id) {
            foreach ($shop->branches as $branch) {
                CatalogSync::syncToBranch($shop, $branch);
            }
        }

        Cache::put($cacheKey, ['product_id' => $product->id, 'barcode' => $product->barcode, 'name' => $product->name_en], now()->addHours(24));

        Activity::log('product.article.create', "নতুন আর্টিকেল '{$product->name_en}' যোগ করা হয়েছে।", $product);

        return back()->with('success', "আর্টিকেল {$product->name_en} যোগ হয়েছে।")
            ->with('productId', $product->id)->with('productBarcode', $product->barcode);
    }

    public function sizeGroupSettingsEdit()
    {
        $shop = Tenancy::shop();

        return Inertia::render('App/Articles/SizeGroupSettings', [
            'groups' => $shop->kidsShoeSizeGroups(),
            'isCustomized' => $shop->articleSettingsShop()->kids_shoe_size_groups !== null,
            // which categories use these groups — the ranges mean nothing
            // until a category is pointed at them (see isKidsCategory)
            'categories' => ProductCategory::orderBy('name')->get(['id', 'name', 'name_en']),
            // the business's picks translated into THIS shop's own category
            // ids, so a branch opening this page sees its own boxes ticked
            'selectedCategoryIds' => $this->localCategoryIdsFor($shop->sizeGroupCategoryCodes()),
        ]);
    }

    public function sizeGroupSettingsUpdate(Request $request)
    {
        $data = $request->validate([
            'groups' => ['required', 'array', 'min:1'],
            'groups.*.label' => ['required', 'string', 'max:50'],
            'groups.*.min_size' => ['required', 'integer', 'min:0'],
            'groups.*.max_size' => ['required', 'integer', 'gte:groups.*.min_size'],
            'category_ids' => ['nullable', 'array'],
            'category_ids.*' => ['integer'],
        ]);

        $groups = $data['groups'];
        usort($groups, fn ($a, $b) => $a['min_size'] <=> $b['min_size']);
        for ($i = 1; $i < count($groups); $i++) {
            if ($groups[$i]['min_size'] <= $groups[$i - 1]['max_size']) {
                return back()->withErrors(['groups' => "'{$groups[$i - 1]['label']}' আর '{$groups[$i]['label']}' গ্রুপের সাইজ রেঞ্জ একে অপরের সাথে মিলে যাচ্ছে — প্রতিটা গ্রুপের রেঞ্জ আলাদা হতে হবে।"]);
            }
        }

        // Only this shop's own categories — an id from another tenant would
        // silently turn the feature on for a category this shop can't see.
        $codes = ProductCategory::whereIn('id', $data['category_ids'] ?? [])
            ->pluck('code')
            ->map(fn ($code) => strtoupper(trim((string) $code)))
            ->filter()->unique()->values()->all();

        // Saved on the business's settings shop, whichever branch is
        // saving, so both Baby Park branches price by the same groups —
        // Khaled set these up once and the other branch's user saw
        // nothing. Stored as codes, which mean the same category in every
        // branch (see Shop::sizeGroupCategoryCodes()).
        Tenancy::shop()->articleSettingsShop()->update([
            'kids_shoe_size_groups' => $groups,
            'size_group_category_codes' => $codes,
        ]);

        return back()->with('success', 'সাইজ-গ্রুপ সেটিংস সংরক্ষণ করা হয়েছে।');
    }

    /** The current shop's own category ids for a set of business-wide category codes — the inverse of Shop::sizeGroupCategoryCodes(). */
    private function localCategoryIdsFor(array $codes): array
    {
        if ($codes === []) {
            return [];
        }

        return ProductCategory::get(['id', 'code'])
            ->filter(fn ($c) => in_array(strtoupper(trim((string) $c->code)), $codes, true))
            ->pluck('id')->map('intval')->values()->all();
    }

    /** docs/ARTICLE_SERIES_PLAN.md §9 — editable রং-কোড list, suggestion-prefilled client-side (see resources/js/support/colorCodeSuggestions.js, the same table as ArticleBarcode::COLOR_CODE_SUGGESTIONS). */
    public function colorCodeSettingsEdit()
    {
        $shop = Tenancy::shop();

        return Inertia::render('App/Articles/ColorCodeSettings', [
            'colorCodeMap' => $shop->colorCodeMap(),
            'barcodeIncludeSize' => $shop->barcodeIncludeSize(),
        ]);
    }

    public function colorCodeSettingsUpdate(Request $request)
    {
        $data = $request->validate([
            'rows' => ['required', 'array', 'min:1'],
            'rows.*.color' => ['required', 'string', 'max:50'],
            'rows.*.code' => ['required', 'string', 'max:10'],
            'barcode_include_size' => ['required', 'boolean'],
        ]);

        $map = [];
        $usedCodes = [];
        foreach ($data['rows'] as $row) {
            $color = trim($row['color']);
            $code = strtoupper(trim($row['code']));
            if (isset($usedCodes[$code]) && $usedCodes[$code] !== $color) {
                return back()->withErrors(['rows' => "কোড \"{$code}\" ইতিমধ্যে \"{$usedCodes[$code]}\"-এর জন্য ব্যবহার হচ্ছে — প্রতিটা রঙের কোড অবশ্যই আলাদা হতে হবে।"]);
            }
            $usedCodes[$code] = $color;
            $map[$color] = $code;
        }

        // business-level, like the size groups above: a branch encoding a
        // colour its own way would print a different barcode for the very
        // article CatalogSync handed it
        Tenancy::shop()->articleSettingsShop()->update(['color_code_map' => $map, 'barcode_include_size' => $data['barcode_include_size']]);

        return back()->with('success', 'রং-কোড সেটিংস সংরক্ষণ করা হয়েছে।');
    }

    /**
     * The cost-code digit map (digit -> letter) that hides a purchase
     * price inside the barcode. COST_CODE_DIGIT_MAP_DEFAULTS only covers
     * the digits Khaled's own import data proved (0,1,3,4,5,6) — the rest
     * are deliberately unset rather than guessed, so a cost containing one
     * of them simply can't be encoded. That was reported as a dead end
     * live ("Fix cost-code digit map from Settings" pointing at a screen
     * that didn't exist); this is that screen.
     */
    public function costCodeSettingsEdit()
    {
        $shop = Tenancy::shop();

        return Inertia::render('App/Articles/CostCodeSettings', [
            'costCodeDigitMap' => $shop->costCodeDigitMap(),
        ]);
    }

    public function costCodeSettingsUpdate(Request $request)
    {
        $data = $request->validate([
            // one entry per digit 0-9, each a single letter
            'map' => ['required', 'array'],
            'map.*' => ['nullable', 'string', 'max:1', 'regex:/^[A-Za-z]$/'],
        ]);

        $map = [];
        $usedLetters = [];
        foreach (range(0, 9) as $digit) {
            $letter = strtoupper(trim((string) ($data['map'][$digit] ?? '')));
            if ($letter === '') {
                continue; // still unmapped — allowed, that digit just can't be encoded yet
            }
            // Z terminates every cost code (ArticleBarcode::COST_SUFFIX), so
            // a digit encoded as Z would make the end of a code ambiguous.
            if ($letter === 'Z') {
                return back()->withErrors(['map' => 'Z অক্ষরটা প্রতিটা কস্ট-কোডের শেষে বসে, তাই কোনো অঙ্কের জন্য Z দেওয়া যাবে না — অন্য একটা অক্ষর বাছুন।']);
            }
            // A letter shared by two digits would decode a cost back to the
            // wrong number, silently — the whole point of this map is that
            // encode and decode are exact inverses.
            if (isset($usedLetters[$letter])) {
                return back()->withErrors(['map' => "অক্ষর \"{$letter}\" একসাথে {$usedLetters[$letter]} আর {$digit} — দুই অঙ্কের জন্য দেওয়া হয়েছে। প্রতিটা অঙ্কের অক্ষর আলাদা হতে হবে, নইলে বারকোড থেকে দাম ভুল পড়া হবে।"]);
            }
            $usedLetters[$letter] = $digit;
            $map[(string) $digit] = $letter;
        }

        Tenancy::shop()->articleSettingsShop()->update(['cost_code_digit_map' => $map]);

        return back()->with('success', 'কস্ট-কোড সেটিংস সংরক্ষণ করা হয়েছে।');
    }

    private function resolveCategory(array $data): ProductCategory|\Illuminate\Http\RedirectResponse
    {
        if (! empty($data['category_id'])) {
            $category = ProductCategory::find($data['category_id']);
            if (! $category) {
                return back()->withErrors(['category_id' => 'ক্যাটাগরি পাওয়া যায়নি।']);
            }
            if (! $category->code) {
                if (empty($data['category_code'])) {
                    return back()->withErrors(['category_code' => "'{$category->name}' ক্যাটাগরির কোনো কোড সেট করা নেই — একটা কোড দিন (যেমন L)।"]);
                }
                $category->code = strtoupper($data['category_code']);
                $category->save();
            }

            return $category;
        }

        if (empty($data['new_category_name']) || empty($data['category_code'])) {
            return back()->withErrors(['new_category_name' => 'নতুন ক্যাটাগরির নাম ও কোড দুটোই দিতে হবে।']);
        }

        return ProductCategory::create([
            'name' => $data['new_category_name'],
            'name_en' => $data['new_category_name'],
            'emoji' => '👟',
            'code' => strtoupper($data['category_code']),
        ]);
    }

    private function resolveSupplier(string $manufacturerCode): Supplier
    {
        $code = strtoupper(trim($manufacturerCode));

        return Supplier::where('short_code', $code)->first()
            ?? Supplier::create(['name' => $code, 'short_code' => $code, 'payable' => 0]);
    }

    private function resolveUnit(array $data): ?int
    {
        if (! empty($data['unit_id'])) {
            return $data['unit_id'];
        }
        if (! empty($data['new_unit_name'])) {
            return Unit::create(['name' => $data['new_unit_name'], 'name_en' => $data['new_unit_name']])->id;
        }

        return null;
    }

    /**
     * Does this category price by size group (3 boxes) rather than one
     * flat price?
     *
     * The business's picked list wins. It was name-matching alone before,
     * against "Kids' Shoes"/"বাচ্চাদের জুতা" — and Baby Park, the shop
     * this was built for, calls their category "BABY", so the three price
     * boxes never appeared for them. The names stay as a fallback so every
     * shop that relied on them keeps working untouched.
     *
     * Matched on the category's CODE, not its id: a branch's categories
     * are its own rows with their own ids, so an id only ever means
     * something in the shop that stored it (see sizeGroupCategoryCodes()).
     */
    private function isKidsCategory(ProductCategory $category): bool
    {
        $codes = Tenancy::shop()->sizeGroupCategoryCodes();
        if ($codes !== []) {
            return in_array(strtoupper(trim((string) $category->code)), $codes, true);
        }

        return in_array(trim((string) $category->name_en), self::KIDS_CATEGORY_NAMES, true)
            || in_array(trim((string) $category->name), self::KIDS_CATEGORY_NAMES, true);
    }
}
