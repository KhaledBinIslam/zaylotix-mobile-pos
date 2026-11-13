<?php

namespace App\Console\Commands;

use App\Models\ProductColorBarcode;
use App\Models\Shop;
use App\Support\CatalogSync;
use Illuminate\Console\Command;

/**
 * CLI counterpart to the owner's "ক্যাটালগ সিঙ্ক করুন" button
 * (BranchController::syncCatalog) — runs CatalogSync::syncBusiness() for
 * the business a shop belongs to, from this host. Exists because waiting
 * on someone to press the button is not a fix: Baby Park's branch sat
 * without color-barcode rows (the CatalogSync gap patched earlier) until
 * a sync actually ran.
 */
class ShopsSyncBusiness extends Command
{
    protected $signature = 'shops:sync-business {--shop=}';

    protected $description = 'Runs the owner "sync catalog" action (CatalogSync::syncBusiness) for the business the given shop belongs to.';

    public function handle(): int
    {
        $shop = Shop::withoutGlobalScopes()->find($this->option('shop'));
        if (! $shop) {
            $this->error('শপ পাওয়া যায়নি (--shop=<id>)।');

            return self::FAILURE;
        }

        $mainShop = $shop->parent_shop_id
            ? Shop::withoutGlobalScopes()->find($shop->parent_shop_id)
            : $shop;
        $branchIds = Shop::withoutGlobalScopes()->where('parent_shop_id', $mainShop->id)->pluck('id');

        if ($branchIds->isEmpty()) {
            $this->error("শপ {$mainShop->id} ({$mainShop->name}) এর কোনো শাখা নেই — সিঙ্ক করার কিছু নেই।");

            return self::FAILURE;
        }

        $before = ProductColorBarcode::withoutGlobalScopes()->whereIn('shop_id', $branchIds)->count();
        CatalogSync::syncBusiness($mainShop);
        $after = ProductColorBarcode::withoutGlobalScopes()->whereIn('shop_id', $branchIds)->count();

        $this->info("'{$mainShop->name}' (id {$mainShop->id}) → {$branchIds->count()}টা শাখা সিঙ্ক হলো। শাখার color-barcode: {$before} → {$after}");

        return self::SUCCESS;
    }
}
