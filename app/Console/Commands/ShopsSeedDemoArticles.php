<?php

namespace App\Console\Commands;

use App\Models\Shop;
use App\Support\DemoArticleCatalog;
use Illuminate\Console\Command;

/**
 * Khaled: "demo account 3 ta teo article onujai color size onujai procur
 * product thakte hobe" — adds a rich article-wise catalog (color+size
 * variants, scannable barcodes, stocked) to an EXISTING demo shop,
 * alongside whatever it already carries. Same demo/trial guard as
 * shops:fill-demo-stock, same idempotent top-up behavior as
 * shop:setup-demo-clothing.
 */
class ShopsSeedDemoArticles extends Command
{
    protected $signature = 'shops:seed-demo-articles {--shop=} {--catalog=clothing} {--articles=6} {--qty=20} {--force}';

    protected $description = 'Seeds an article-wise color+size demo catalog (clothing|footwear) into an existing demo shop.';

    public function handle(): int
    {
        $shop = Shop::withoutGlobalScopes()->find($this->option('shop'));
        if (! $shop) {
            $this->error('শপ পাওয়া যায়নি (--shop=<id>)।');

            return self::FAILURE;
        }

        $catalogName = (string) $this->option('catalog');
        $catalogs = ['clothing' => [DemoArticleCatalog::CLOTHING, 'DK'], 'footwear' => [DemoArticleCatalog::FOOTWEAR, 'DF']];
        if (! isset($catalogs[$catalogName])) {
            $this->error('--catalog=clothing বা --catalog=footwear দিন।');

            return self::FAILURE;
        }

        if (! in_array($shop->account_type, ['demo', 'trial'], true) && ! $this->option('force')) {
            $this->error("'{$shop->name}' (id {$shop->id}) এর account_type '{$shop->account_type}' — ডেমো/ট্রায়াল না। সত্যিই চাইলে --force দিন।");

            return self::FAILURE;
        }

        [$catalog, $prefix] = $catalogs[$catalogName];
        $articles = max(1, (int) $this->option('articles'));
        $qty = (float) $this->option('qty');

        $result = DemoArticleCatalog::seedInto($shop, $catalog, $prefix, $articles, $qty);

        $this->info("শপ {$shop->id} ({$shop->name}) — {$catalogName} ক্যাটালগ: {$result['products']}টা আর্টিকেল, {$result['variants']}টা ভ্যারিয়েন্ট (প্রতিটাতে স্টক {$qty})।");

        return self::SUCCESS;
    }
}
