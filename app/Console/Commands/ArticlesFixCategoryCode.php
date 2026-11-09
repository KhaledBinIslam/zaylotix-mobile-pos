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
    protected $signature = 'articles:fix-category-code {--shop=} {--name= : shops whose name contains this, instead of an id} {--old-code=} {--by-product= : find the category via a product name substring (e.g. a serial like 70001) instead of retyping its code — use this when --old-code keeps reporting 0 articles because of an invisible encoding mismatch} {--category-id= : the exact category id to fix (from articles:list-broken-categories) — use this when --by-product also keeps missing it (e.g. the serial itself was typed in Bengali-script digits)} {--new-code=} {--dry-run}';

    protected $description = 'Renames a category\'s (and its articles\') code — for a code that was typed in the wrong script and never produced a scannable barcode.';

    public function handle(): int
    {
        $oldCode = trim((string) $this->option('old-code'));
        $byProduct = trim((string) $this->option('by-product'));
        $categoryId = $this->option('category-id');
        $newCode = strtoupper(trim((string) $this->option('new-code')));

        if (($oldCode === '' && $byProduct === '' && ! $categoryId) || $newCode === '') {
            $this->error('--old-code=<বর্তমান কোড>, --by-product=<আর্টিকেলের নামের অংশ> অথবা --category-id=<id> এর একটা, আর --new-code=<নতুন ইংরেজি কোড> দিতে হবে।');

            return self::FAILURE;
        }
        if (! preg_match('/^[A-Za-z0-9]+$/', $newCode)) {
            $this->error('নতুন কোড শুধু ইংরেজি অক্ষর/সংখ্যা হতে হবে (যেমন TPB) — বাংলা বা অন্য চিহ্ন চলবে না।');

            return self::FAILURE;
        }

        if ($categoryId) {
            return $this->runForCategoryId((int) $categoryId, $newCode, (bool) $this->option('dry-run'));
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
            if ($byProduct !== '') {
                $this->runForShopByProduct($shop, $byProduct, $newCode, $isDryRun);
            } else {
                $this->runForShop($shop, $oldCode, $newCode, $isDryRun);
            }
        }

        if (! $isDryRun) {
            $this->newLine();
            $this->info('এরপর এটা চালান যাতে সাইজ/রং-ভিত্তিক বারকোডও ঠিক হয়ে যায়:');
            $this->line('  php artisan articles:regenerate-barcodes --name="'.($this->option('name') ?: $this->option('shop')).'" --fix-unprintable');
        }

        return self::SUCCESS;
    }

    /**
     * Baby Park live: even --by-product kept missing the real article —
     * the serial typed on the command line ("70001") didn't byte-match
     * what's in the product's own name (most likely Bengali-script digits
     * inside the serial itself, not ASCII ones). An exact category id from
     * articles:list-broken-categories needs no text matched at all.
     */
    private function runForCategoryId(int $categoryId, string $newCode, bool $isDryRun): int
    {
        $category = ProductCategory::withoutGlobalScopes()->find($categoryId);
        if (! $category) {
            $this->error("ক্যাটাগরি id {$categoryId} পাওয়া যায়নি।");

            return self::FAILURE;
        }

        $shop = Shop::withoutGlobalScopes()->find($category->shop_id);
        if (! $shop) {
            $this->error("ক্যাটাগরি id {$categoryId}-এর শপ পাওয়া যায়নি।");

            return self::FAILURE;
        }

        $this->newLine();
        $this->line("<fg=cyan>SHOP {$shop->id} — {$shop->name}</>");

        $supplierCache = [];
        $this->processCategory($shop, $category, $newCode, $isDryRun, $supplierCache, [$category->id]);

        if (! $isDryRun) {
            $this->newLine();
            $this->info('এরপর এটা চালান যাতে সাইজ/রং-ভিত্তিক বারকোডও ঠিক হয়ে যায়:');
            $this->line("  php artisan articles:regenerate-barcodes --shop={$shop->id} --fix-unprintable");
        }

        return self::SUCCESS;
    }

    private function runForShop(Shop $shop, string $oldCode, string $newCode, bool $isDryRun): void
    {
        // Baby Park live: "School" and the real article's own category both
        // carried the code "টেপবড়" — a code is explicitly allowed to be
        // shared by more than one category (see resolveCategory()'s own
        // comment), so EVERY one of them has to be renamed, not just
        // whichever happens to come back first.
        $categories = ProductCategory::withoutGlobalScopes()
            ->where('shop_id', $shop->id)
            ->whereRaw('UPPER(code) = ?', [strtoupper($oldCode)])
            ->get();

        if ($categories->isEmpty()) {
            $this->line("  এই শপে \"{$oldCode}\" কোডের কোনো ক্যাটাগরি নেই — বাদ। (SSH/টার্মিনাল দিয়ে বাংলা কোড রিটাইপ করলে মাঝে মাঝে আসল ডেটার সাথে বাইট মেলে না — এর বদলে --by-product=<আর্টিকেলের সিরিয়াল, যেমন 70001> ব্যবহার করুন।)");

            return;
        }

        $excludeIds = $categories->pluck('id')->all();
        $supplierCache = [];

        foreach ($categories as $category) {
            $this->processCategory($shop, $category, $newCode, $isDryRun, $supplierCache, $excludeIds);
        }
    }

    /**
     * Finds the category to rename via a product NAME substring (e.g. a
     * plain-ASCII serial like "70001") instead of asking the old code to be
     * retyped — Baby Park live: retyping "টেপবড়" over SSH kept matching a
     * 0-article "School" category instead of the real one, because the
     * typed text didn't byte-match what's actually stored (invisible
     * Unicode normalization difference). The category's OWN stored `code`
     * is used as-is, straight from the DB, so this never depends on typing
     * non-ASCII text through a terminal at all.
     */
    private function runForShopByProduct(Shop $shop, string $substring, string $newCode, bool $isDryRun): void
    {
        $products = Product::withoutGlobalScopes()->where('shop_id', $shop->id)
            ->where(function ($q) use ($substring) {
                $q->where('name', 'like', '%'.$substring.'%')
                    ->orWhere('name_en', 'like', '%'.$substring.'%');
            })->get();

        if ($products->isEmpty()) {
            $this->line("  \"{$substring}\" লেখাটা এই শপের কোনো প্রোডাক্টের নামে পাওয়া যায়নি — বাদ।");

            return;
        }

        $categoryIds = $products->pluck('category_id')->filter()->unique()->values();
        if ($categoryIds->isEmpty()) {
            $this->line('  মিলে যাওয়া প্রোডাক্টগুলোর কোনো ক্যাটাগরি নেই — বাদ।');

            return;
        }

        // Baby Park live: a plain numeric substring like "70001" also
        // matches an article whose category was ALREADY fixed by an
        // earlier run (e.g. "TPB-70001"), dragging that category along and
        // relabeling it yet again under the new code — observed churning
        // one branch category TPB → FITA → TCHOT → DAMI across four runs.
        // Only a category whose code is STILL unprintable is a real target.
        $categories = ProductCategory::withoutGlobalScopes()->whereIn('id', $categoryIds)->get()
            ->filter(fn (ProductCategory $c) => ! ArticleBarcode::isPrintable((string) $c->code));

        if ($categories->isEmpty()) {
            $this->line("  \"{$substring}\" মিলে যাওয়া প্রোডাক্টগুলোর ক্যাটাগরি কোড আগেই ইংরেজি — ঠিক করার কিছু নেই।");

            return;
        }

        $excludeIds = $categories->pluck('id')->all();
        $supplierCache = [];

        foreach ($categories as $category) {
            $this->processCategory($shop, $category, $newCode, $isDryRun, $supplierCache, $excludeIds);
        }
    }

    private function processCategory(Shop $shop, ProductCategory $category, string $newCode, bool $isDryRun, array &$supplierCache, array $excludeIds = []): void
    {
        $oldCode = trim((string) $category->code);
        if ($oldCode === '') {
            $this->warn("  ক্যাটাগরি \"{$category->name}\" (id {$category->id}) এর কোনো কোড নেই — বাদ।");

            return;
        }

        $clash = ProductCategory::withoutGlobalScopes()
            ->where('shop_id', $shop->id)
            ->whereNotIn('id', array_unique(array_merge($excludeIds, [$category->id])))
            ->whereRaw('UPPER(code) = ?', [$newCode])->exists();
        if ($clash) {
            $this->warn("  \"{$newCode}\" কোডটা এই শপে অন্য একটা ক্যাটাগরি আগে থেকেই ব্যবহার করছে — অন্য একটা কোড বাছুন।");

            return;
        }

        $pattern = '/^'.preg_quote($oldCode, '/').'-(\d+)$/i';
        $products = Product::withoutGlobalScopes()->where('shop_id', $shop->id)
            ->where('category_id', $category->id)->get()
            ->filter(fn (Product $p) => preg_match($pattern, trim((string) $p->name), $m) === 1);

        $this->line("  ক্যাটাগরি \"{$category->name}\" (id {$category->id}, কোড {$oldCode} → {$newCode}) — {$products->count()}টা আর্টিকেল পাওয়া গেছে।");

        if ($isDryRun) {
            foreach ($products as $p) {
                $newName = preg_replace($pattern, $newCode.'-$1', trim((string) $p->name));
                $this->line("    \"{$p->name}\" → \"{$newName}\" (barcode {$p->barcode} সহ) হতো।");
            }

            return;
        }

        ProductCategory::withoutGlobalScopes()->whereKey($category->id)->update(['code' => $newCode]);

        $this->renameAndRebuild($shop, $products, $pattern, $newCode, $supplierCache);
    }

    /** @param  \Illuminate\Support\Collection<int,Product>  $products */
    private function renameAndRebuild(Shop $shop, $products, string $pattern, string $newCode, array &$supplierCache): void
    {
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
