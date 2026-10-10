<?php

namespace App\Console\Commands;

use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Shop;
use Illuminate\Console\Command;

/**
 * Read-only: finds every variant-having product across every shop where
 * products.stock no longer equals SUM(variants.stock) — the invariant every
 * other part of the app assumes always holds (see ProductVariantController's
 * docblock). Before today's stock-transfer fix, a variant product's transfer
 * decremented/incremented the PLAIN products.stock only, never touching any
 * ProductVariant row, so any branch that ever transferred a variant product
 * is very likely to show up here. Never writes anything — Khaled decides how
 * (or whether) to reconcile each one found.
 *
 * Deliberately avoids Product::whereHas('variants')/with('variants') — both
 * ProductVariant AND Sale/SalesReturn/etc. carry their own BelongsToTenant
 * global scope, which a relation's own internal query does NOT automatically
 * drop just because the OUTER query called withoutGlobalScopes(). Running
 * this from `php artisan` (no authenticated tenant, Tenancy::id() is null)
 * meant whereHas('variants') silently matched nothing at all — confirmed
 * live: a planted 99-vs-5 mismatch reported "none found" until this was
 * rewritten to query ProductVariant directly, itself withoutGlobalScopes().
 */
class VariantsStockMismatch extends Command
{
    protected $signature = 'variants:stock-mismatch';

    protected $description = 'Read-only: lists every variant product where products.stock does not equal the sum of its variants\' stock.';

    public function handle(): int
    {
        $variantSums = ProductVariant::withoutGlobalScopes()
            ->selectRaw('product_id, SUM(stock) as total')
            ->groupBy('product_id')
            ->pluck('total', 'product_id');

        if ($variantSums->isEmpty()) {
            $this->info('কোনো variant পণ্যই নেই — চেক করার কিছু নেই।');

            return self::SUCCESS;
        }

        $products = Product::withoutGlobalScopes()->whereIn('id', $variantSums->keys())->get(['id', 'shop_id', 'name', 'stock']);
        $shopNames = Shop::withoutGlobalScopes()->whereIn('id', $products->pluck('shop_id')->unique())->pluck('name', 'id');

        $rows = [];
        foreach ($products as $product) {
            $variantSum = (float) $variantSums->get($product->id, 0);
            $productStock = (float) $product->stock;

            if (abs($variantSum - $productStock) > 0.001) {
                $rows[] = [
                    $shopNames->get($product->shop_id, "shop#{$product->shop_id}"),
                    $product->name,
                    $product->id,
                    number_format($productStock, 3),
                    number_format($variantSum, 3),
                    number_format($productStock - $variantSum, 3),
                ];
            }
        }

        if (empty($rows)) {
            $this->info('কোনো mismatch পাওয়া যায়নি — সব variant পণ্যের stock ঠিক আছে।');

            return self::SUCCESS;
        }

        $this->table(
            ['Shop', 'Product', 'Product ID', 'products.stock', 'Sum of variants', 'Difference'],
            $rows
        );

        $this->newLine();
        $this->info(count($rows).'টা পণ্যে mismatch পাওয়া গেছে। এই command কিছু পরিবর্তন করেনি — শুধু রিপোর্ট দেখিয়েছে।');

        return self::SUCCESS;
    }
}
