<?php

namespace App\Console\Commands;

use App\Models\ArticleSeriesCounter;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\ProductColorBarcode;
use App\Models\ProductColorBarcodeAlias;
use App\Models\ProductVariant;
use App\Models\Shop;
use App\Models\Unit;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Liberty Bali: cloned from Baby Park (shops:clone-from-template) so the
 * article/barcode SYSTEM matched, but the client wants their own serial
 * numbering from scratch, not Baby Park's — "serail milteche na, natun
 * kore ora ready korte cacche." Wipes the catalog (categories, units,
 * products, variants, and their color-barcode/alias rows + this shop's
 * own article-series counters) back to empty, leaving the shop itself,
 * its owner login, features, and settings (memo/label/color-code/etc.)
 * completely untouched — only ever scoped to one explicit --shop id,
 * never a fuzzy --name match, since this is destructive.
 */
class ShopsClearCatalog extends Command
{
    protected $signature = 'shops:clear-catalog {--shop=} {--dry-run}';

    protected $description = 'Wipes one shop\'s product catalog (categories/units/products/variants) back to empty. Shop, owner, features, and settings are untouched.';

    public function handle(): int
    {
        $shopId = $this->option('shop');
        if (! $shopId) {
            $this->error('--shop=<id> দিতে হবে।');

            return self::FAILURE;
        }

        $shop = Shop::withoutGlobalScopes()->find($shopId);
        if (! $shop) {
            $this->error("শপ id {$shopId} পাওয়া যায়নি।");

            return self::FAILURE;
        }

        $categoryCount = ProductCategory::withoutGlobalScopes()->where('shop_id', $shop->id)->count();
        $unitCount = Unit::withoutGlobalScopes()->where('shop_id', $shop->id)->count();
        $productCount = Product::withoutGlobalScopes()->where('shop_id', $shop->id)->withTrashed()->count();
        $variantCount = ProductVariant::withoutGlobalScopes()->where('shop_id', $shop->id)->withTrashed()->count();

        $this->line("শপ {$shop->id} — {$shop->name}: {$categoryCount}টা ক্যাটাগরি, {$unitCount}টা ইউনিট, {$productCount}টা প্রোডাক্ট, {$variantCount}টা ভ্যারিয়েন্ট মুছে যাবে। শপ/owner/ফিচার/সেটিংস অপরিবর্তিত থাকবে।");

        if ($this->option('dry-run')) {
            $this->info('dry-run — কিছু মোছা হয়নি।');

            return self::SUCCESS;
        }

        DB::transaction(function () use ($shop) {
            $productIds = Product::withoutGlobalScopes()->where('shop_id', $shop->id)->withTrashed()->pluck('id');

            ProductVariant::withoutGlobalScopes()->where('shop_id', $shop->id)->withTrashed()->forceDelete();
            ProductColorBarcodeAlias::withoutGlobalScopes()->whereIn('product_id', $productIds)->delete();
            ProductColorBarcode::withoutGlobalScopes()->whereIn('product_id', $productIds)->delete();
            Product::withoutGlobalScopes()->where('shop_id', $shop->id)->withTrashed()->forceDelete();
            ProductCategory::withoutGlobalScopes()->where('shop_id', $shop->id)->delete();
            Unit::withoutGlobalScopes()->where('shop_id', $shop->id)->delete();

            // the counter is keyed by main_shop_id (shared business-wide,
            // see ArticleSerial's own docblock) — for an independent shop
            // like this one, that's just its own id
            ArticleSeriesCounter::where('main_shop_id', $shop->parent_shop_id ?? $shop->id)->delete();
        });

        $this->info("শপ {$shop->id}-এর ক্যাটালগ মুছে ফেলা হয়েছে — এখন খালি, নতুন করে শুরু করা যাবে।");

        return self::SUCCESS;
    }
}
