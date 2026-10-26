<?php

namespace App\Console\Commands;

use App\Models\Shop;
use Illuminate\Console\Command;

/** Read-only: lists every feature key currently granted to a given shop (and its branches, if it's a main shop) — never changes anything. */
class ShopListFeatures extends Command
{
    protected $signature = 'shop:list-features {--shop=}';

    protected $description = 'Read-only: lists which features are granted to a shop (and its branches, if a main shop).';

    public function handle(): int
    {
        $shopId = $this->option('shop');
        if (! $shopId) {
            $this->error('--shop=<id> দিতে হবে।');

            return self::FAILURE;
        }

        $shop = Shop::withoutGlobalScopes()->find($shopId);
        if (! $shop) {
            $this->error("শপ ID {$shopId} পাওয়া যায়নি।");

            return self::FAILURE;
        }

        $this->printShop($shop);

        if (! $shop->parent_shop_id) {
            foreach (Shop::withoutGlobalScopes()->where('parent_shop_id', $shop->id)->get() as $branch) {
                $this->newLine();
                $this->printShop($branch);
            }
        }

        $this->newLine();
        $this->info('এই command কিছু পরিবর্তন করেনি — শুধু রিপোর্ট দেখিয়েছে।');

        return self::SUCCESS;
    }

    private function printShop(Shop $shop): void
    {
        $this->line("শপ: {$shop->name} (ID {$shop->id})".($shop->parent_shop_id ? " — শাখা, মূল শপ: {$shop->parent_shop_id}" : ' — মূল শপ'));
        $keys = $shop->featureKeys()->sort()->values();
        if ($keys->isEmpty()) {
            $this->warn('  কোনো ফিচার চালু নেই।');

            return;
        }
        foreach ($keys as $key) {
            $this->line("  - {$key}");
        }
    }
}
