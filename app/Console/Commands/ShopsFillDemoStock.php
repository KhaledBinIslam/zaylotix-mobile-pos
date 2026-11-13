<?php

namespace App\Console\Commands;

use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Shop;
use Illuminate\Console\Command;

/**
 * Khaled: "ey 2 ta shop e enough stock tule dao jate amar polapain demo
 * dekhate pare" — top up EVERY article of a demo shop so the sales team
 * can demo real sells without hitting out-of-stock walls.
 *
 * Direct writes on purpose (no Purchase/Activity trail): these are demo
 * shops, not books anyone reconciles. Guarded so it can't be pointed at a
 * paying client's shop by a typo'd id: refuses unless the shop's
 * account_type is demo/trial, or --force is passed explicitly.
 */
class ShopsFillDemoStock extends Command
{
    protected $signature = 'shops:fill-demo-stock {--shop=} {--qty=20} {--force}';

    protected $description = 'Sets every live article/variant of a demo shop to the given stock (default 20).';

    public function handle(): int
    {
        $shop = Shop::withoutGlobalScopes()->find($this->option('shop'));
        if (! $shop) {
            $this->error('শপ পাওয়া যায়নি (--shop=<id>)।');

            return self::FAILURE;
        }

        $qty = (float) $this->option('qty');
        if ($qty <= 0) {
            $this->error('--qty ধনাত্মক সংখ্যা হতে হবে।');

            return self::FAILURE;
        }

        if (! in_array($shop->account_type, ['demo', 'trial'], true) && ! $this->option('force')) {
            $this->error("'{$shop->name}' (id {$shop->id}) এর account_type '{$shop->account_type}' — ডেমো/ট্রায়াল না। সত্যিই চাইলে --force দিন।");

            return self::FAILURE;
        }

        $variantCount = ProductVariant::withoutGlobalScopes()
            ->where('shop_id', $shop->id)->whereNull('deleted_at')
            ->update(['stock' => $qty]);

        // a variant product's own stock column mirrors the sum of its live
        // variants (same invariant applyStockDelta/CatalogSync maintain);
        // a plain product just gets the qty directly
        $products = Product::withoutGlobalScopes()
            ->where('shop_id', $shop->id)->whereNull('deleted_at')->get(['id']);
        $plainCount = 0;
        foreach ($products as $p) {
            $liveVariants = ProductVariant::withoutGlobalScopes()
                ->where('product_id', $p->id)->whereNull('deleted_at')->count();
            $stock = $liveVariants > 0 ? $liveVariants * $qty : $qty;
            if ($liveVariants === 0) {
                $plainCount++;
            }
            Product::withoutGlobalScopes()->whereKey($p->id)->update(['stock' => $stock]);
        }

        $this->info("শপ {$shop->id} ({$shop->name}) — {$variantCount}টা ভ্যারিয়েন্ট ও {$plainCount}টা সাধারণ আর্টিকেল প্রতিটাতে {$qty} করে স্টক বসানো হলো ({$products->count()}টা আর্টিকেল মোট)।");

        return self::SUCCESS;
    }
}
