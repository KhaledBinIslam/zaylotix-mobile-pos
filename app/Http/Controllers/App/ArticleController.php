<?php

namespace App\Http\Controllers\App;

use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\ProductVariant;
use App\Models\Supplier;
use App\Models\Unit;
use App\Support\Activity;
use App\Support\ArticleBarcode;
use App\Support\ArticleSerial;
use App\Support\CatalogSync;
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
            'kidsShoeSizeGroups' => $shop->kidsShoeSizeGroups(),
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

        return response()->json(['serial' => ArticleSerial::peekNext($shop, $categoryCode)]);
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
            return back()->withErrors(['cost' => 'এই cost-এর কিছু অংক এখনো বারকোড সেটিংসে ম্যাপ করা নেই — Settings থেকে cost-code digit map ঠিক করুন।']);
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

        $product = DB::transaction(function () use ($shop, $category, $supplier, $unitId, $data, $costCode, $isKids, $kidsPrices, $basePrice, $variantRows) {
            $serial = ArticleSerial::next($shop, $category->code);
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
            'isCustomized' => $shop->kids_shoe_size_groups !== null,
        ]);
    }

    public function sizeGroupSettingsUpdate(Request $request)
    {
        $data = $request->validate([
            'groups' => ['required', 'array', 'min:1'],
            'groups.*.label' => ['required', 'string', 'max:50'],
            'groups.*.min_size' => ['required', 'integer', 'min:0'],
            'groups.*.max_size' => ['required', 'integer', 'gte:groups.*.min_size'],
        ]);

        $groups = $data['groups'];
        usort($groups, fn ($a, $b) => $a['min_size'] <=> $b['min_size']);
        for ($i = 1; $i < count($groups); $i++) {
            if ($groups[$i]['min_size'] <= $groups[$i - 1]['max_size']) {
                return back()->withErrors(['groups' => "'{$groups[$i - 1]['label']}' আর '{$groups[$i]['label']}' গ্রুপের সাইজ রেঞ্জ একে অপরের সাথে মিলে যাচ্ছে — প্রতিটা গ্রুপের রেঞ্জ আলাদা হতে হবে।"]);
            }
        }

        Tenancy::shop()->update(['kids_shoe_size_groups' => $groups]);

        return back()->with('success', 'সাইজ-গ্রুপ সেটিংস সংরক্ষণ করা হয়েছে।');
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

    private function isKidsCategory(ProductCategory $category): bool
    {
        return in_array(trim((string) $category->name_en), self::KIDS_CATEGORY_NAMES, true)
            || in_array(trim((string) $category->name), self::KIDS_CATEGORY_NAMES, true);
    }
}
