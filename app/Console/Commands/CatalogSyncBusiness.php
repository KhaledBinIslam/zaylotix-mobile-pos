<?php

namespace App\Console\Commands;

use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Shop;
use App\Support\CatalogSync;
use Illuminate\Console\Command;

/**
 * The owner-facing version of this is one button on the More page
 * ("শাখায় ক্যাটালগ সিঙ্ক করুন"). This is the same thing from a shell,
 * so a chain can be put back in step during support without anyone
 * having to be logged in as the right shop — and so the result can be
 * read out as numbers afterwards.
 */
class CatalogSyncBusiness extends Command
{
    protected $signature = 'catalog:sync-business {--shop= : any shop in the business} {--name= : shops whose name contains this, instead of an id}';

    protected $description = 'Makes every shop in a business carry the same catalog, lifting branch-only rows up to the main shop first.';

    public function handle(): int
    {
        $shops = Shop::withoutGlobalScopes()
            ->when($this->option('shop'), fn ($q, $id) => $q->whereKey($id))
            ->when($this->option('name'), fn ($q, $n) => $q->where('name', 'like', '%'.$n.'%'))
            ->orderBy('id')->get();

        if (! $this->option('shop') && ! $this->option('name')) {
            $this->error('--shop=<id> অথবা --name=<দোকানের নাম> দিতে হবে।');

            return self::FAILURE;
        }
        if ($shops->isEmpty()) {
            $this->error('কোনো শপ পাওয়া যায়নি।');

            return self::FAILURE;
        }

        // one business, however many of its shops matched the filter
        $mainIds = $shops->map(fn (Shop $s) => $s->parent_shop_id ?? $s->id)->unique();

        foreach ($mainIds as $mainId) {
            $main = Shop::withoutGlobalScopes()->find($mainId);
            $this->newLine();
            $this->line("<fg=cyan>{$main->name} (শপ {$main->id}) — সিঙ্ক করা হচ্ছে</>");

            CatalogSync::syncBusiness($main);

            $shopIds = Shop::withoutGlobalScopes()->where('parent_shop_id', $main->id)->pluck('id')->prepend($main->id);
            foreach ($shopIds as $shopId) {
                $products = Product::withoutGlobalScopes()->where('shop_id', $shopId)->whereNull('deleted_at')->count();
                $variants = ProductVariant::withoutGlobalScopes()->where('shop_id', $shopId)->whereNull('deleted_at')->count();
                $units = (float) ProductVariant::withoutGlobalScopes()->where('shop_id', $shopId)->whereNull('deleted_at')->sum('stock');
                $name = Shop::withoutGlobalScopes()->whereKey($shopId)->value('name');
                $this->line("  শপ {$shopId} ({$name}): পণ্য {$products}টা, সাইজ {$variants}টা, মোট স্টক {$units}");
            }
        }

        $this->newLine();

        return self::SUCCESS;
    }
}
