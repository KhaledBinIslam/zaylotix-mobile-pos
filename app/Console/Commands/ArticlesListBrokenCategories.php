<?php

namespace App\Console\Commands;

use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\Shop;
use App\Support\ArticleBarcode;
use Illuminate\Console\Command;

/**
 * Baby Park live: --by-product=<serial> kept missing the real article
 * ("টেপবড়-70001") even after it stopped re-sweeping already-fixed
 * categories — the "70001" typed on the command line didn't byte-match
 * what's actually in the product's own name (likely Bengali-script digits,
 * not ASCII ones, inside the serial itself). Read-only: lists every
 * category whose code is still unprintable, with its exact id, so
 * articles:fix-category-code --category-id=<id> can target it directly —
 * no text, Bengali or otherwise, has to be typed through a terminal at all.
 */
class ArticlesListBrokenCategories extends Command
{
    protected $signature = 'articles:list-broken-categories {--shop=} {--name=}';

    protected $description = 'Read-only: lists every category whose code is still unprintable, with its id and sample products, for articles:fix-category-code --category-id=.';

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

            $categories = ProductCategory::withoutGlobalScopes()->where('shop_id', $shop->id)->get()
                ->filter(fn (ProductCategory $c) => ! ArticleBarcode::isPrintable((string) $c->code));

            if ($categories->isEmpty()) {
                $this->line('  সব ক্যাটাগরির কোডই ইংরেজি — ঠিক করার কিছু নেই।');

                continue;
            }

            foreach ($categories as $category) {
                $products = Product::withoutGlobalScopes()->where('shop_id', $shop->id)
                    ->where('category_id', $category->id)->orderBy('id')->get();
                $samples = $products->take(3)->pluck('name')->implode(', ');

                $this->line("  id {$category->id} — ক্যাটাগরি \"{$category->name}\" — {$products->count()}টা প্রোডাক্ট".($samples !== '' ? " (যেমন: {$samples})" : ' (কোনো প্রোডাক্ট নেই)'));
            }
        }

        return self::SUCCESS;
    }
}
