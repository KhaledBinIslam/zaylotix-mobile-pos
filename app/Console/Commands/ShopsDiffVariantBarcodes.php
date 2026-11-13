<?php

namespace App\Console\Commands;

use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Shop;
use Illuminate\Console\Command;

/**
 * Read-only: for every main-shop product, checks its branch's own copy
 * (matched by master_product_id) has the SAME variant barcodes — not just
 * the same variant COUNT, which shops:show-settings already confirmed
 * matches for Baby Park/branch 81 but evidently isn't the whole story
 * (Khaled: 5-6 scanned at the branch, only 1 resolved).
 */
class ShopsDiffVariantBarcodes extends Command
{
    protected $signature = 'shops:diff-variant-barcodes {--main=} {--branch=}';

    protected $description = 'Compares every main-shop product variant\'s barcode against its branch counterpart.';

    public function handle(): int
    {
        $main = Shop::withoutGlobalScopes()->find($this->option('main'));
        $branch = Shop::withoutGlobalScopes()->find($this->option('branch'));
        if (! $main || ! $branch) {
            $this->error('--main=<id> --branch=<id> দিতে হবে।');

            return self::FAILURE;
        }

        $checked = 0;
        $mismatches = 0;

        foreach (Product::withoutGlobalScopes()->where('shop_id', $main->id)->whereNull('deleted_at')->get(['id', 'name']) as $mp) {
            $bp = Product::withoutGlobalScopes()->where('shop_id', $branch->id)->where('master_product_id', $mp->id)->first();
            if (! $bp) {
                $this->line("NO BRANCH PRODUCT for {$mp->name}");
                $mismatches++;

                continue;
            }

            $branchVariants = ProductVariant::withoutGlobalScopes()->where('product_id', $bp->id)->whereNull('deleted_at')->get();

            foreach (ProductVariant::withoutGlobalScopes()->where('product_id', $mp->id)->whereNull('deleted_at')->get() as $mv) {
                $checked++;
                $matches = $branchVariants->where('size', $mv->size)->where('color', $mv->color);

                if ($matches->isEmpty()) {
                    $this->line("MISSING variant {$mp->name} {$mv->size}/{$mv->color} (main barcode {$mv->barcode})");
                    $mismatches++;

                    continue;
                }
                if ($matches->count() > 1) {
                    $this->line("DUPLICATE branch variant {$mp->name} {$mv->size}/{$mv->color} — {$matches->count()} rows: ".$matches->pluck('id')->implode(','));
                    $mismatches++;
                }

                $bv = $matches->first();
                if ($bv->barcode !== $mv->barcode) {
                    $this->line("BARCODE MISMATCH {$mp->name} {$mv->size}/{$mv->color} main={$mv->barcode} branch={$bv->barcode}");
                    $mismatches++;
                }
            }
        }

        $this->info("checked={$checked} mismatches={$mismatches}");

        return self::SUCCESS;
    }
}
