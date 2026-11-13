<?php

namespace App\Console\Commands;

use App\Models\Product;
use App\Models\ProductColorBarcode;
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

        foreach (Product::withoutGlobalScopes()->where('shop_id', $main->id)->whereNull('deleted_at')->get(['id', 'name', 'stock']) as $mp) {
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
                // shared_stock_pool's whole promise: one real number, every
                // pool member row carries it — Khaled's own follow-up
                // question ("branch 1-e sell hole branch 2-eo stock kome
                // toh?"), checked directly rather than just asserted.
                if ((float) $bv->stock !== (float) $mv->stock) {
                    $this->line("STOCK MISMATCH {$mp->name} {$mv->size}/{$mv->color} main_stock={$mv->stock} branch_stock={$bv->stock}");
                    $mismatches++;
                }
            }

            if ((float) $bp->stock !== (float) $mp->stock) {
                $this->line("PRODUCT STOCK MISMATCH {$mp->name} main_stock={$mp->stock} branch_stock={$bp->stock}");
                $mismatches++;
            }

            // tier 2 (docs/ARTICLE_SERIES_PLAN.md §9) — the actual barcode
            // printed on most of Baby Park's shoe labels when
            // barcode_include_size is off. Never compared before now:
            // shops:sync-business only ever checked the COUNT (409 vs
            // 404), never each color's own barcode VALUE.
            $branchColorBarcodes = ProductColorBarcode::withoutGlobalScopes()->where('product_id', $bp->id)->get();
            foreach (ProductColorBarcode::withoutGlobalScopes()->where('product_id', $mp->id)->get() as $mcb) {
                $checked++;
                $match = $branchColorBarcodes->firstWhere('color', $mcb->color);
                if (! $match) {
                    $this->line("MISSING color barcode {$mp->name} {$mcb->color} (main barcode {$mcb->barcode})");
                    $mismatches++;

                    continue;
                }
                if ($match->barcode !== $mcb->barcode) {
                    $this->line("COLOR BARCODE MISMATCH {$mp->name} {$mcb->color} main={$mcb->barcode} branch={$match->barcode}");
                    $mismatches++;
                }
            }
            // the reverse direction — a color barcode that exists at the
            // branch but not at main would never show up in the loop
            // above at all, and a scan for it would still resolve fine
            // (it's real, synced data) — but it's worth surfacing as an
            // orphan if the two counts (409 vs 404, seen earlier) are
            // ever revisited.
            $mainColorBarcodes = ProductColorBarcode::withoutGlobalScopes()->where('product_id', $mp->id)->pluck('color');
            foreach ($branchColorBarcodes as $bcb) {
                if (! $mainColorBarcodes->contains($bcb->color)) {
                    $this->line("ORPHAN branch-only color barcode {$bp->name} {$bcb->color} branch={$bcb->barcode}");
                }
            }
        }

        $this->info("checked={$checked} mismatches={$mismatches}");

        return self::SUCCESS;
    }
}
