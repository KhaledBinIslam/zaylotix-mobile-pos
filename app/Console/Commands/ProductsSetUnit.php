<?php

namespace App\Console\Commands;

use App\Models\Product;
use App\Models\Shop;
use App\Models\Unit;
use App\Support\CatalogSync;
use Illuminate\Console\Command;

/**
 * Khaled: "jutar unit pcs na, jora (pair)" — backfills unit_id for
 * articles products:import-articles already created before its own
 * unit-resolution fix (it read the sheet's "PAIR" column but never wired
 * it to Product.unit_id, so every one of those products fell back to the
 * generic "পিস" display default). Find-or-creates the Unit by name so a
 * repeat run never duplicates it.
 */
class ProductsSetUnit extends Command
{
    protected $signature = 'products:set-unit {--shop=} {--name=} {--name-en=} {--dry-run}';

    protected $description = 'Sets unit_id (find-or-create by name) on every product of a shop that has no unit yet. --dry-run previews only.';

    public function handle(): int
    {
        $shopId = $this->option('shop');
        $name = $this->option('name');
        $nameEn = $this->option('name-en') ?: $name;
        if (! $shopId || ! $name) {
            $this->error('--shop=<id> এবং --name=<ইউনিটের নাম, যেমন জোড়া> দুটোই দিতে হবে।');

            return self::FAILURE;
        }

        $shop = Shop::withoutGlobalScopes()->find($shopId);
        if (! $shop) {
            $this->error("শপ ID {$shopId} পাওয়া যায়নি।");

            return self::FAILURE;
        }

        $isDryRun = (bool) $this->option('dry-run');

        $unit = Unit::withoutGlobalScopes()->where('shop_id', $shop->id)->where('name', $name)->first();
        if (! $unit) {
            $this->line("নতুন ইউনিট \"{$name}\" ({$nameEn}) তৈরি ".($isDryRun ? 'হতো।' : 'হলো।'));
            if (! $isDryRun) {
                $unit = Unit::create(['shop_id' => $shop->id, 'name' => $name, 'name_en' => $nameEn]);
            }
        }

        $products = Product::withoutGlobalScopes()->where('shop_id', $shop->id)->whereNull('unit_id')->get();
        foreach ($products as $product) {
            $this->line("আর্টিকেল \"{$product->name_en}\" — unit \"{$name}\" ".($isDryRun ? 'হতো।' : 'বসানো হলো।'));
            if (! $isDryRun) {
                $product->update(['unit_id' => $unit->id]);
            }
        }

        if (! $isDryRun && $shop->hasFeature('shared_catalog') && ! $shop->parent_shop_id) {
            foreach ($shop->branches as $branch) {
                CatalogSync::syncToBranch($shop, $branch);
            }
        }

        $this->newLine();
        $this->info(($isDryRun ? 'প্রিভিউ: ' : '')."{$products->count()}টা আর্টিকেলের unit \"{$name}\" ".($isDryRun ? 'হতো।' : 'করা হলো, এবং শাখায় সিঙ্ক করা হলো।'));

        return self::SUCCESS;
    }
}
