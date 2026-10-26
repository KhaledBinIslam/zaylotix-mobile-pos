<?php

namespace App\Console\Commands;

use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Shop;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * One-off repair for the bug fixed in App\Support\CatalogSync (see its own
 * docblock): before that fix, a freshly-synced branch copy of a pooled
 * product/variant always started at stock 0 instead of the main shop's
 * real count, so it sat wrong until some unrelated stock event happened to
 * correct it. This reconciles every already-synced branch copy for a given
 * main shop RIGHT NOW, by calling Product/ProductVariant::applyStockDelta(0)
 * — the exact same, already-tested pooling mechanism every real sale/stock-
 * in already goes through, not a new way of writing stock. Since
 * applyStockDelta() always bases the pool's shared value on whichever
 * member has the lowest id (orderBy('id')->first()), and a main shop's own
 * row is always created before any branch copy, this is guaranteed to pull
 * every branch copy up (or down) to match the main shop's own current
 * number, never the other way around.
 *
 * Safe to run more than once — a product/variant already in sync is simply
 * rewritten to the same value it already had (delta 0, no-op in effect).
 */
class ProductsReconcileStockPool extends Command
{
    protected $signature = 'products:reconcile-stock-pool {--shop=} {--dry-run}';

    protected $description = 'Repairs branch copies left at the wrong stock by a sync that predates the CatalogSync starting-stock fix. --dry-run previews only.';

    public function handle(): int
    {
        $shopId = $this->option('shop');
        if (! $shopId) {
            $this->error('--shop=<main shop id> দিতে হবে।');

            return self::FAILURE;
        }

        $shop = Shop::withoutGlobalScopes()->find($shopId);
        if (! $shop) {
            $this->error("শপ ID {$shopId} পাওয়া যায়নি।");

            return self::FAILURE;
        }
        if ($shop->parent_shop_id) {
            $this->error('এটা মূল শপের ID হতে হবে, শাখার না। মূল শপের ID দিন।');

            return self::FAILURE;
        }
        if (! $shop->hasFeature('shared_stock_pool')) {
            $this->error("শপ \"{$shop->name}\"-এ shared_stock_pool ফিচার চালু নেই — রিকনসাইল করার কিছু নেই।");

            return self::FAILURE;
        }

        $isDryRun = (bool) $this->option('dry-run');
        $fixedProducts = 0;
        $fixedVariants = 0;

        foreach (Product::withoutGlobalScopes()->where('shop_id', $shop->id)->get() as $product) {
            $memberIds = $product->stockPoolMemberIds();
            if ($memberIds->count() <= 1) {
                continue; // no branch copy linked to this product at all
            }

            $mismatched = Product::withoutGlobalScopes()->whereIn('id', $memberIds)
                ->where('stock', '!=', $product->stock)->exists();
            if ($mismatched) {
                $this->line("আর্টিকেল \"{$product->name_en}\" — শাখার স্টক মূল শপের ({$product->stock}) সাথে মিলছে না, ঠিক করা হচ্ছে।");
                $fixedProducts++;
                if (! $isDryRun) {
                    DB::transaction(fn () => $product->applyStockDelta(0));
                }
            }

            foreach (ProductVariant::withoutGlobalScopes()->where('product_id', $product->id)->get() as $variant) {
                // ProductVariant::stockPoolMemberIds() lazy-loads its `product`
                // relation through Product's normal tenant scope, which won't
                // resolve correctly in this console context (no Tenancy set) —
                // pre-populate it with the $product we already fetched above
                $variant->setRelation('product', $product);
                $variantMemberIds = $variant->stockPoolMemberIds();
                if ($variantMemberIds->count() <= 1) {
                    continue;
                }
                $variantMismatched = ProductVariant::withoutGlobalScopes()->whereIn('id', $variantMemberIds)
                    ->where('stock', '!=', $variant->stock)->exists();
                if ($variantMismatched) {
                    $this->line("  - ভ্যারিয়েন্ট {$variant->label()} — মিলছে না, ঠিক করা হচ্ছে।");
                    $fixedVariants++;
                    if (! $isDryRun) {
                        DB::transaction(fn () => $variant->applyStockDelta(0));
                    }
                }
            }
        }

        $this->newLine();
        $this->info(($isDryRun ? 'প্রিভিউ: ' : '')."{$fixedProducts}টা আর্টিকেল, {$fixedVariants}টা ভ্যারিয়েন্ট ঠিক ".($isDryRun ? 'করা হতো।' : 'করা হলো।'));

        return self::SUCCESS;
    }
}
