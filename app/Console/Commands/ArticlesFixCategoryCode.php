<?php

namespace App\Console\Commands;

use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\Shop;
use App\Models\Supplier;
use App\Support\ArticleBarcode;
use App\Support\ArticleVariant;
use Illuminate\Console\Command;

/**
 * Baby Park live: "JUMP-টেপবড়-70001-JXXZ-WHT..." — a category code typed
 * in Bengali (createArticle() had no ASCII check at the time) goes
 * straight into every article barcode built from it. Code128 cannot
 * encode it and JsBarcode cannot even draw it, so the whole article —
 * every size, every colour — was unscannable from the day it was
 * created, on every branch. There was no settings screen to fix a
 * category's code after the fact (unlike a colour's own short code, which
 * already has one), so this is that fix, from the shell.
 *
 * Renames the category's code AND every article's own name/barcode under
 * it (an article is literally named "{code}-{serial}" — see
 * ArticleVariant::serialOf()'s own docblock), so the whole article-series
 * system recognizes it as an article again. Deliberately does NOT touch
 * variant or colour barcodes itself — those are rebuilt by re-running
 * articles:regenerate-barcodes --fix-unprintable straight after, which
 * already knows how to do that safely (aliasing, collision checks) and
 * this has no reason to duplicate.
 */
class ArticlesFixCategoryCode extends Command
{
    protected $signature = 'articles:fix-category-code {--shop=} {--name= : shops whose name contains this, instead of an id} {--old-code=} {--new-code=} {--dry-run}';

    protected $description = 'Renames a category\'s (and its articles\') code — for a code that was typed in the wrong script and never produced a scannable barcode.';

    public function handle(): int
    {
        $oldCode = trim((string) $this->option('old-code'));
        $newCode = strtoupper(trim((string) $this->option('new-code')));

        if ($oldCode === '' || $newCode === '') {
            $this->error('--old-code=<বর্তমান কোড> আর --new-code=<নতুন ইংরেজি কোড> দুটোই দিতে হবে।');

            return self::FAILURE;
        }
        if (! preg_match('/^[A-Za-z0-9]+$/', $newCode)) {
            $this->error('নতুন কোড শুধু ইংরেজি অক্ষর/সংখ্যা হতে হবে (যেমন TPB) — বাংলা বা অন্য চিহ্ন চলবে না।');

            return self::FAILURE;
        }

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

        $shops = ArticlesSettingsDoctor::withBranchesOf($shops);
        $isDryRun = (bool) $this->option('dry-run');

        foreach ($shops as $shop) {
            $this->newLine();
            $this->line("<fg=cyan>SHOP {$shop->id} — {$shop->name}</>");
            $this->runForShop($shop, $oldCode, $newCode, $isDryRun);
        }

        if (! $isDryRun) {
            $this->newLine();
            $this->info('এরপর এটা চালান যাতে সাইজ/রং-ভিত্তিক বারকোডও ঠিক হয়ে যায়:');
            $this->line('  php artisan articles:regenerate-barcodes --name="'.($this->option('name') ?: $this->option('shop')).'" --fix-unprintable');
        }

        return self::SUCCESS;
    }

    private function runForShop(Shop $shop, string $oldCode, string $newCode, bool $isDryRun): void
    {
        $category = ProductCategory::withoutGlobalScopes()
            ->where('shop_id', $shop->id)
            ->whereRaw('UPPER(code) = ?', [strtoupper($oldCode)])
            ->first();

        if (! $category) {
            $this->line("  এই শপে \"{$oldCode}\" কোডের কোনো ক্যাটাগরি নেই — বাদ।");

            return;
        }

        $clash = ProductCategory::withoutGlobalScopes()
            ->where('shop_id', $shop->id)->where('id', '!=', $category->id)
            ->whereRaw('UPPER(code) = ?', [$newCode])->exists();
        if ($clash) {
            $this->warn("  \"{$newCode}\" কোডটা এই শপে অন্য একটা ক্যাটাগরি আগে থেকেই ব্যবহার করছে — অন্য একটা কোড বাছুন।");

            return;
        }

        $pattern = '/^'.preg_quote($oldCode, '/').'-(\d+)$/i';
        $products = Product::withoutGlobalScopes()->where('shop_id', $shop->id)
            ->where('category_id', $category->id)->get()
            ->filter(fn (Product $p) => preg_match($pattern, trim((string) $p->name), $m) === 1);

        $this->line("  ক্যাটাগরি \"{$category->name}\" ({$oldCode} → {$newCode}) — {$products->count()}টা আর্টিকেল পাওয়া গেছে।");

        if ($isDryRun) {
            foreach ($products as $p) {
                $newName = preg_replace($pattern, $newCode.'-$1', trim((string) $p->name));
                $this->line("    \"{$p->name}\" → \"{$newName}\" (barcode {$p->barcode} সহ) হতো।");
            }

            return;
        }

        ProductCategory::withoutGlobalScopes()->whereKey($category->id)->update(['code' => $newCode]);

        $supplierCache = [];
        foreach ($products as $product) {
            $newName = preg_replace($pattern, $newCode.'-$1', trim((string) $product->name));

            // serialOf() reads the name against category->code — a fresh,
            // unsaved probe carrying the NEW code (not product's own
            // relation, which still points at the category row with its
            // now-already-updated code anyway, but this is explicit and
            // doesn't depend on relation-cache timing)
            $probe = new Product;
            $probe->forceFill(['name' => $newName]);
            $probe->setRelation('category', (new ProductCategory)->forceFill(['code' => $newCode]));
            $serial = ArticleVariant::serialOf($probe);

            $supplierId = $product->supplier_id ?? ($product->master_product_id
                ? Product::withoutGlobalScopes()->whereKey($product->master_product_id)->value('supplier_id')
                : null);
            if ($supplierId && ! array_key_exists($supplierId, $supplierCache)) {
                $supplierCache[$supplierId] = Supplier::withoutGlobalScopes()->find($supplierId);
            }
            $mfr = strtoupper(trim((string) ($supplierCache[$supplierId]->short_code ?? '')));
            $costCode = $product->cost !== null ? ArticleBarcode::encodeCost($shop, (int) $product->cost) : null;

            if ($serial === null || $mfr === '' || $costCode === null) {
                $this->warn("    \"{$product->name}\" → \"{$newName}\" — নাম বদলানো হলো, কিন্তু বারকোড পুনর্গঠন করা যায়নি (ম্যানুফ্যাকচারার/কস্ট-কোড পাওয়া যায়নি) — articles:regenerate-barcodes দিয়ে পরে ঠিক করুন।");
                Product::withoutGlobalScopes()->whereKey($product->id)->update(['name' => $newName, 'name_en' => $newName]);

                continue;
            }

            $newBarcode = ArticleBarcode::buildArticleBarcode($shop, $mfr, $newCode, $serial, $costCode);
            Product::withoutGlobalScopes()->whereKey($product->id)->update([
                'name' => $newName, 'name_en' => $newName, 'barcode' => $newBarcode,
            ]);
            $this->line("    \"{$product->name}\" → \"{$newName}\", বারকোড {$product->barcode} → {$newBarcode}");
        }
    }
}
