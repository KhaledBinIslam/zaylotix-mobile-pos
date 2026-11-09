<?php

namespace App\Console\Commands;

use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\Shop;
use App\Support\ArticleBarcode;
use Illuminate\Console\Command;

/**
 * Baby Park live: articles:list-broken-categories came back clean (every
 * remaining unprintable-coded category had 0 products) while
 * "টেপবড়-70001" was still reported unprintable by regenerate-barcodes —
 * meaning this one product's own `name` was never actually tied to its
 * category's code at all (an orphaned/typo'd name, not a category-code
 * bug) and needed to be found directly. Read-only: lists every product
 * whose own name is still unprintable, with its exact id, for
 * articles:fix-category-code --product-id=.
 */
class ArticlesListBrokenProducts extends Command
{
    protected $signature = 'articles:list-broken-products {--shop=} {--name=}';

    protected $description = 'Read-only: lists every product whose own name is still unprintable, with its id, for articles:fix-category-code --product-id=.';

    public function handle(): int
    {
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

        foreach ($shops as $shop) {
            $this->newLine();
            $this->line("<fg=cyan>SHOP {$shop->id} — {$shop->name}</>");

            $products = Product::withoutGlobalScopes()->where('shop_id', $shop->id)->get()
                ->filter(fn (Product $p) => ! ArticleBarcode::isPrintable(trim((string) $p->name)));

            if ($products->isEmpty()) {
                $this->line('  সব প্রোডাক্টের নামই ইংরেজি — ঠিক করার কিছু নেই।');

                continue;
            }

            foreach ($products as $p) {
                $category = $p->category_id ? ProductCategory::withoutGlobalScopes()->find($p->category_id) : null;
                $categoryLabel = $category ? "\"{$category->name}\" (কোড {$category->code})" : 'নেই';
                $this->line("  id {$p->id} — \"{$p->name}\" — ক্যাটাগরি: {$categoryLabel} — বারকোড: ".($p->barcode ?: '(খালি)'));
            }
        }

        return self::SUCCESS;
    }
}
