<?php

namespace App\Console\Commands;

use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Shop;
use App\Support\CatalogSync;
use Illuminate\Console\Command;

/**
 * Khaled: "3 pair or er kom thakle stock alert asbe" — the import command
 * (and the one-screen entry form) don't ask for a reorder point, so every
 * imported article's products.reorder_point/product_variants.reorder_point
 * sits null, which Pos/Index.vue's stockLevel()/variantStockLevel() then
 * treats as the generic default (6), not the "3 pairs" threshold he
 * actually wants for this shop's shoes. Sets BOTH columns (product-level
 * for Stock's own low-stock count, variant-level for POS's per-size/color
 * warning) so the two screens never disagree about what "low" means.
 */
class ProductsSetReorderPoint extends Command
{
    protected $signature = 'products:set-reorder-point {--shop=} {--value=} {--dry-run}';

    protected $description = 'Sets reorder_point on every product and variant of a shop (or --category) to a given value. --dry-run previews only.';

    public function handle(): int
    {
        $shopId = $this->option('shop');
        $value = $this->option('value');
        if (! $shopId || $value === null) {
            $this->error('--shop=<id> এবং --value=<সংখ্যা> দুটোই দিতে হবে।');

            return self::FAILURE;
        }
        if (! ctype_digit((string) $value)) {
            $this->error('--value অবশ্যই একটা অ-ঋণাত্মক সংখ্যা হতে হবে।');

            return self::FAILURE;
        }

        $shop = Shop::withoutGlobalScopes()->find($shopId);
        if (! $shop) {
            $this->error("শপ ID {$shopId} পাওয়া যায়নি।");

            return self::FAILURE;
        }

        $isDryRun = (bool) $this->option('dry-run');
        $value = (int) $value;

        $products = Product::withoutGlobalScopes()->where('shop_id', $shop->id)->get();
        foreach ($products as $product) {
            $this->line("আর্টিকেল \"{$product->name_en}\" — reorder point {$value} ".($isDryRun ? 'হতো।' : 'করা হলো।'));
            if (! $isDryRun) {
                $product->update(['reorder_point' => $value]);
                ProductVariant::withoutGlobalScopes()->where('product_id', $product->id)->update(['reorder_point' => $value]);
            }
        }

        if (! $isDryRun && $shop->hasFeature('shared_catalog') && ! $shop->parent_shop_id) {
            foreach ($shop->branches as $branch) {
                CatalogSync::syncToBranch($shop, $branch);
            }
        }

        $this->newLine();
        $this->info(($isDryRun ? 'প্রিভিউ: ' : '')."{$products->count()}টা আর্টিকেল (ও তাদের ভ্যারিয়েন্ট) reorder point {$value} ".($isDryRun ? 'হতো।' : 'করা হলো, এবং শাখায় সিঙ্ক করা হলো।'));

        return self::SUCCESS;
    }
}
