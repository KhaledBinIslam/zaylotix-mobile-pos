<?php

namespace App\Console\Commands;

use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Shop;
use Illuminate\Console\Command;

/**
 * products.stock is documented everywhere in this codebase as "the live
 * sum of its variants" (see CatalogSync's own docblocks) but nothing
 * actually RE-asserts that invariant on demand — it only gets written by
 * whichever specific code path happened to touch it. Found live via
 * shops:diff-variant-barcodes: Baby Park branch 81 had 5 products whose
 * own stock mirror had drifted a few units from SUM(variants.stock),
 * while every individual variant's own (correctly pooled) stock matched
 * the main shop exactly — the real per-size counts were never wrong,
 * just this one summary number. Recomputes it directly; never touches a
 * variant's own stock.
 */
class ShopsRepairProductStockMirror extends Command
{
    protected $signature = 'shops:repair-product-stock-mirror {--shop=} {--dry-run}';

    protected $description = 'Recomputes every variant-product\'s stock mirror as SUM(variants.stock) for a shop.';

    public function handle(): int
    {
        $shop = Shop::withoutGlobalScopes()->find($this->option('shop'));
        if (! $shop) {
            $this->error('শপ পাওয়া যায়নি (--shop=<id>)।');

            return self::FAILURE;
        }

        $fixed = 0;
        foreach (Product::withoutGlobalScopes()->where('shop_id', $shop->id)->whereNull('deleted_at')->get(['id', 'name', 'stock']) as $p) {
            $variantCount = ProductVariant::withoutGlobalScopes()->where('product_id', $p->id)->whereNull('deleted_at')->count();
            if ($variantCount === 0) {
                continue; // a plain product's own stock is never a derived mirror
            }

            $sum = (float) ProductVariant::withoutGlobalScopes()->where('product_id', $p->id)->whereNull('deleted_at')->sum('stock');
            if (abs($sum - (float) $p->stock) < 0.001) {
                continue;
            }

            $this->line("{$p->name}: {$p->stock} → {$sum}");
            $fixed++;
            if (! $this->option('dry-run')) {
                Product::withoutGlobalScopes()->whereKey($p->id)->update(['stock' => $sum]);
            }
        }

        $this->info(($this->option('dry-run') ? '[dry-run] ' : '')."শপ {$shop->id} ({$shop->name}) — {$fixed}টা প্রোডাক্টের স্টক মিরর ঠিক করা হলো।");

        return self::SUCCESS;
    }
}
