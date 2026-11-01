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
    protected $signature = 'articles:regenerate-barcodes {--shop=} {--name= : shops whose name contains this, instead of an id} {--variants-only : only fill in missing variant barcodes, never touch a colour barcode} {--fix-unprintable : also REPLACE a variant barcode that Code128 cannot encode} {--add-missing-z : append the compulsory Z terminator to a cost code that lacks it} {--dry-run}';

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

        // a branch has its own Product/ProductVariant rows, so it needs
        // its own pass — and "--name='Baby Park'" reached only one of the
        // two shops sharing this catalog
        $shops = ArticlesSettingsDoctor::withBranchesOf($shops);

        foreach ($shops as $shop) {
            $this->newLine();
            $this->line("<fg=cyan>SHOP {$shop->id} — {$shop->name}</>");
            $this->runForShop($shop);
        }

        return self::SUCCESS;
    }

    /**
     * Puts the compulsory Z back on a cost code that lacks it — Khaled:
     * "NSXX price hole eta hobe NSXXZ".
     *
     * Every cost code ends in Z; it marks the end of the code, which is
     * why no digit may be mapped to it. encodeCost() always appends one,
     * but the IMPORTED rows carried the original stickers' codes, and
     * those had it on some rows and not others — the same cost 1350
     * arrived as both "NSRXZ" and "NSRX".
     *
     * Only ever acts where the Z is provably the ONLY difference: the
     * barcode the shop's own settings say this article should carry,
     * minus its final Z, must equal the barcode it actually carries.
     * Anything else is a different problem (a cost corrected after the
     * stickers were printed, say) and is reported rather than guessed at
     * — because this rewrites what the next label prints.
     *
     * The variants' barcodes are rebuilt from the same prefix, by exact
     * string replacement rather than recomputation, so a variant whose
     * barcode was hand-typed or differs for its own reasons is left
     * alone.
     */
    private function addMissingZ(Shop $shop, Product $product, bool $isDryRun): bool
    {
        $current = (string) $product->barcode;
        if ($current === '' || str_ends_with($current, 'Z') || $product->cost === null) {
            return false;
        }

        $serial = ArticleVariant::serialOf($product);
        $mfr = strtoupper(trim((string) $product->supplier?->short_code));
        $categoryCode = strtoupper(trim((string) $product->category?->code));
        $costCode = ArticleBarcode::encodeCost($shop, (int) $product->cost);
        if ($serial === null || $mfr === '' || $categoryCode === '' || $costCode === null) {
            return false;
        }

        $shouldBe = ArticleBarcode::buildArticleBarcode($shop, $mfr, $categoryCode, $serial, $costCode);
        if ($shouldBe !== $current.'Z') {
            $this->warn("  ⚠ \"{$product->name_en}\" — {$current} বাদ দেওয়া হলো: শুধু Z যোগ করলে {$shouldBe} হওয়ার কথা ছিল, মেলেনি।");

            return false;
        }

        $this->line("  আর্টিকেল \"{$product->name_en}\" — {$current} → {$shouldBe} ".($isDryRun ? 'হতো।' : 'করা হলো।'));

        if (! $isDryRun) {
            Product::withoutGlobalScopes()->whereKey($product->id)->update(['barcode' => $shouldBe]);
            $product->barcode = $shouldBe;

            foreach (ProductVariant::withoutGlobalScopes()->where('product_id', $product->id)
                ->where('barcode', 'like', $current.'-%')->get() as $variant) {
                $variant->forceFill([
                    'barcode' => $shouldBe.substr((string) $variant->barcode, strlen($current)),
                ])->saveQuietly();
            }
        }

        return true;
    }

    private function runForShop(Shop $shop): void
    {
        $isDryRun = (bool) $this->option('dry-run');
        $new = 0;
        $changed = 0;
        $unchanged = 0;

        $variantsFilled = 0;
        $variantsStuck = 0;
        $variantsUnprintable = 0;
        $colorsWithoutCode = [];
        $zAdded = 0;

        foreach (Product::withoutGlobalScopes()->where('shop_id', $shop->id)->get() as $product) {
            if (! $product->barcode) {
                continue; // no article-level barcode to build a color one on top of
            }

            // TenantScope resolves to shop_id = 0 from a console command,
            // so the relations have to be loaded past it by hand or
            // ArticleVariant sees an article with no category at all
            $product->setRelation('category', ProductCategory::withoutGlobalScopes()->find($product->category_id));
            $product->setRelation('supplier', Supplier::withoutGlobalScopes()->find($product->supplier_id));

            if ($this->option('add-missing-z') && $this->addMissingZ($shop, $product, $isDryRun)) {
                $zAdded++;
            }

            /*
             * A size added from the stock screen's grid before that grid
             * knew about articles went in with no barcode — nothing to
             * scan at the counter, and a blank label. By default only a
             * MISSING one is filled: a barcode already on a variant may
             * be printed and stuck on stock.
             *
             * --fix-unprintable widens that to "has nothing a scanner
             * could read". A Bengali barcode was never on a sticker —
             * JsBarcode cannot draw one — so there is no printed label to
             * contradict, but it does still hold the unique index against
             * the correct string, so the right barcode can never be
             * issued while it sits there. Opt-in all the same: it
             * overwrites a column, and that is the owner's call to make
             * from a --dry-run, not mine.
             *
             * whereNull('deleted_at') because withoutGlobalScopes() drops
             * the soft-delete scope too: a removed size would otherwise
             * be handed its barcode back and immediately block the live
             * row that replaced it — the very collision this fixes.
             */
            $candidates = ProductVariant::withoutGlobalScopes()->where('product_id', $product->id)
                ->whereNull('deleted_at')
                ->when(! $this->option('fix-unprintable'), fn ($q) => $q->whereNull('barcode'))
                ->get()
                ->filter(fn (ProductVariant $v) => ! ArticleBarcode::isPrintable($v->barcode));

            foreach ($candidates as $variant) {
                // "could not" has two very different causes, and only one
                // of them is something the owner can act on — so they are
                // counted and reported apart
                if (ArticleVariant::barcodeFor($shop, $product, $variant->color, $variant->size, $variant->cost) === null) {
                    $colorsWithoutCode[(string) $variant->color] = true;
                    $variantsUnprintable++;

                    continue;
                }

                $computed = ArticleVariant::barcodeFor($shop, $product, $variant->color, $variant->size, $variant->cost);

                // "আগেই কারো দখলে" is useless on its own — say WHO, or
                // the owner has nothing to go and look at. Trashed rows
                // included, since those hold the unique index too.
                $holder = ProductVariant::withoutGlobalScopes()
                    ->where('shop_id', $product->shop_id)
                    ->where('barcode', $computed)
                    ->where('id', '!=', $variant->id)
                    ->first();
                if ($holder) {
                    $holderProduct = Product::withoutGlobalScopes()->whereKey($holder->product_id)->first();
                    $this->warn("  ⚠ \"{$product->name_en} / {$variant->label()}\" — {$computed} পাওয়া যাচ্ছে না।");
                    $this->warn("      এটা এখন আছে: \"{$holderProduct?->name_en} / {$holder->label()}\"".($holder->deleted_at ? ' (মুছে ফেলা)' : '').", id {$holder->id}");
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

        if ($this->option('add-missing-z')) {
            $this->info(($isDryRun ? 'প্রিভিউ: ' : '')."শেষে Z বসানো হয়েছে: {$zAdded}টা আর্টিকেলে।");
        }

        if ($colorsWithoutCode) {
            $this->newLine();
            $this->warn("  {$variantsUnprintable}টা ভ্যারিয়েন্টে বারকোড বসানো যায়নি — এই রংগুলোর কোনো ইংরেজি কোড ঠিক করা নেই:");
            $this->warn('    '.implode(', ', array_keys($colorsWithoutCode)));
            $this->warn('  বারকোড শুধু ইংরেজি অক্ষর-সংখ্যা বইতে পারে। অ্যাপে "⚙️ রং-কোড সেটিংস"-এ গিয়ে');
            $this->warn('  এই রংগুলোর কোড বসান (যেমন বাদামী → BRN), তারপর এই কমান্ডটা আবার চালান।');
        }
    }
}
