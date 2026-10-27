<?php

namespace App\Console\Commands;

use App\Models\Product;
use App\Models\ProductColorBarcode;
use App\Models\ProductVariant;
use App\Models\Shop;
use App\Support\ArticleBarcode;
use App\Support\ColorBarcodeResolver;
use Illuminate\Console\Command;

/**
 * docs/ARTICLE_SERIES_PLAN.md §9 — backfills product_color_barcodes for
 * articles created (by products:import-articles or the one-screen entry
 * form) before this feature existed, or whose color-level barcode needs
 * recomputing after a color-code rename / sticker template edit. Never
 * touches a sale/stock/Product/ProductVariant row — see
 * ColorBarcodeResolver's own docblock for the aliasing-before-overwrite
 * guarantee that makes this safe to re-run any time.
 */
class ArticlesRegenerateBarcodes extends Command
{
    protected $signature = 'articles:regenerate-barcodes {--shop=} {--dry-run}';

    protected $description = 'Backfills/refreshes product_color_barcodes for a shop\'s articles. --dry-run previews only.';

    public function handle(): int
    {
        $shopId = $this->option('shop');
        if (! $shopId) {
            $this->error('--shop=<id> দিতে হবে।');

            return self::FAILURE;
        }

        $shop = Shop::withoutGlobalScopes()->find($shopId);
        if (! $shop) {
            $this->error("শপ ID {$shopId} পাওয়া যায়নি।");

            return self::FAILURE;
        }

        $isDryRun = (bool) $this->option('dry-run');
        $new = 0;
        $changed = 0;
        $unchanged = 0;

        foreach (Product::withoutGlobalScopes()->where('shop_id', $shop->id)->get() as $product) {
            if (! $product->barcode) {
                continue; // no article-level barcode to build a color one on top of
            }

            $colors = ProductVariant::withoutGlobalScopes()->where('product_id', $product->id)
                ->whereNotNull('color')->distinct()->pluck('color');

            foreach ($colors as $color) {
                $computed = ArticleBarcode::buildColorBarcode($shop, $product->barcode, $color);
                $existing = ProductColorBarcode::withoutGlobalScopes()
                    ->where('shop_id', $shop->id)->where('product_id', $product->id)->where('color', $color)->first();

                if (! $existing) {
                    $this->line("আর্টিকেল \"{$product->name_en}\" রং {$color} — নতুন বারকোড {$computed} ".($isDryRun ? 'হতো।' : 'তৈরি হলো।'));
                    $new++;
                } elseif ($existing->barcode !== $computed) {
                    $this->line("আর্টিকেল \"{$product->name_en}\" রং {$color} — বারকোড {$existing->barcode} থেকে {$computed}-এ ".($isDryRun ? 'বদলাতো (পুরোনোটা alias থাকবে)।' : 'বদলানো হলো (পুরোনোটা alias হিসেবে রাখা হয়েছে)।'));
                    $changed++;
                } else {
                    $unchanged++;
                }

                if (! $isDryRun) {
                    ColorBarcodeResolver::resolve($shop, $product, $color, $product->barcode);
                }
            }
        }

        $this->newLine();
        $this->info(($isDryRun ? 'প্রিভিউ: ' : '')."{$new}টা নতুন, {$changed}টা বদলানো, {$unchanged}টা অপরিবর্তিত।");

        return self::SUCCESS;
    }
}
