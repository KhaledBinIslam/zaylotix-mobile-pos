<?php

namespace App\Console\Commands;

use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\ProductColorBarcode;
use App\Models\ProductVariant;
use App\Models\Shop;
use App\Models\Supplier;
use App\Support\ArticleBarcode;
use App\Support\ArticleVariant;
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
    protected $signature = 'articles:regenerate-barcodes {--shop=} {--name= : shops whose name contains this, instead of an id} {--variants-only : only fill in missing variant barcodes, never touch a colour barcode} {--dry-run}';

    protected $description = 'Backfills/refreshes colour barcodes, and fills in any variant that has none, for a shop\'s articles. --dry-run previews only.';

    public function handle(): int
    {
        // --name because on production nobody remembers the branch's id,
        // and a shop with branches needs each of them doing (same reason
        // articles:settings-doctor takes one)
        $shops = Shop::withoutGlobalScopes()
            ->when($this->option('shop'), fn ($q, $id) => $q->whereKey($id))
            ->when($this->option('name'), fn ($q, $name) => $q->where('name', 'like', '%'.$name.'%'))
            ->orderBy('id')->get();

        if (! $this->option('shop') && ! $this->option('name')) {
            $this->error('--shop=<id> অথবা --name=<দোকানের নাম> দিতে হবে।');

            return self::FAILURE;
        }

        if ($shops->isEmpty()) {
            $this->error('কোনো শপ পাওয়া যায়নি।');

            return self::FAILURE;
        }

        foreach ($shops as $shop) {
            $this->newLine();
            $this->line("<fg=cyan>SHOP {$shop->id} — {$shop->name}</>");
            $this->runForShop($shop);
        }

        return self::SUCCESS;
    }

    private function runForShop(Shop $shop): void
    {
        $isDryRun = (bool) $this->option('dry-run');
        $new = 0;
        $changed = 0;
        $unchanged = 0;

        $variantsFilled = 0;
        $variantsStuck = 0;

        foreach (Product::withoutGlobalScopes()->where('shop_id', $shop->id)->get() as $product) {
            if (! $product->barcode) {
                continue; // no article-level barcode to build a color one on top of
            }

            // TenantScope resolves to shop_id = 0 from a console command,
            // so the relations have to be loaded past it by hand or
            // ArticleVariant sees an article with no category at all
            $product->setRelation('category', ProductCategory::withoutGlobalScopes()->find($product->category_id));
            $product->setRelation('supplier', Supplier::withoutGlobalScopes()->find($product->supplier_id));

            // A size added from the stock screen's grid before that grid
            // knew about articles went in with no barcode — nothing to
            // scan at the counter, and a blank label. Only ever FILLS a
            // missing one; a barcode already on a variant is never
            // touched, because it may be printed and stuck on stock.
            // whereNull('deleted_at') because withoutGlobalScopes() drops
            // the soft-delete scope too: a removed size would otherwise be
            // handed the barcode back and immediately block the live row
            // that replaced it, which is the very collision this fixes
            foreach (ProductVariant::withoutGlobalScopes()->where('product_id', $product->id)
                ->whereNull('deleted_at')->whereNull('barcode')->get() as $variant) {
                $computed = ArticleVariant::freeBarcodeFor($shop, $product, $variant->color, $variant->size, $variant->cost);
                if ($computed === null) {
                    $variantsStuck++;

                    continue;
                }

                $this->line("  ভ্যারিয়েন্ট \"{$product->name_en} / {$variant->label()}\" — বারকোড {$computed} ".($isDryRun ? 'বসত।' : 'বসানো হলো।'));
                if (! $isDryRun) {
                    $variant->forceFill(['barcode' => $computed])->saveQuietly();
                }
                $variantsFilled++;
            }

            // --variants-only: filling in a missing variant barcode adds
            // something that was never printed, but REBUILDING a colour
            // barcode changes what the next label prints for a colour
            // whose old stickers are already on the shelves. Khaled needed
            // the first without committing to the second.
            if ($this->option('variants-only')) {
                continue;
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

        if ($this->option('variants-only')) {
            $this->info('রঙের বারকোড: ছোঁয়া হয়নি (--variants-only)।');
        } else {
            $this->info(($isDryRun ? 'প্রিভিউ: ' : '')."রঙের বারকোড: {$new}টা নতুন, {$changed}টা বদলানো, {$unchanged}টা অপরিবর্তিত।");
        }
        $this->info(($isDryRun ? 'প্রিভিউ: ' : '')."ভ্যারিয়েন্টের বারকোড: {$variantsFilled}টা বসানো".($variantsStuck ? ", {$variantsStuck}টা পারা যায়নি (ওই বারকোড আগেই কারো দখলে)" : '').'।');
    }
}
