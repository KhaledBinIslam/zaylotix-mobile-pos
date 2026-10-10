<?php

namespace App\Console\Commands;

use App\Models\Product;
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
 */
class VariantsStockMismatch extends Command
{
    protected $signature = 'variants:stock-mismatch';

    protected $description = 'Read-only: lists every variant product where products.stock does not equal the sum of its variants\' stock.';

    public function handle(): int
    {
        $rows = [];

        $products = Product::withoutGlobalScopes()
            ->whereHas('variants')
            ->with(['variants', 'shop:id,name'])
            ->get();

        foreach ($products as $product) {
            $variantSum = (float) $product->variants->sum('stock');
            $productStock = (float) $product->stock;

            if (abs($variantSum - $productStock) > 0.001) {
                $rows[] = [
                    $product->shop?->name ?? "shop#{$product->shop_id}",
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
