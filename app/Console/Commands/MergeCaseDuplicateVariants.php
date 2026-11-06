<?php

namespace App\Console\Commands;

use App\Models\ProductVariant;
use App\Models\SaleItem;
use App\Models\Shop;
use App\Support\Activity;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * One-time live-data repair for a bug fixed in code the same day: typing a
 * colour/size that already existed but in different CASE (e.g. 'Navy Blue'
 * next to an existing 'Navy blue') created a second variant instead of
 * being refused — ProductVariant::cellExists()/cellKey() now block this
 * going forward (every create path), but shops that already have twins
 * need those merged by hand. Seaman's live report: INV-1001 sold 4 off
 * one 'Navy blue' XXL row while a second 'Navy Blue' XXL row sat at 4,
 * untouched — "sell holeo stock minus hocche na".
 *
 * Read-only by default (--dry-run is the implicit safe mode: pass
 * --confirm to actually write). For each product, variants sharing a
 * cellKey(color,size) are a cluster; the SURVIVOR is whichever one has
 * sale history (SaleItem rows) — the real, already-in-use row — or,
 * failing that, the lowest id (the original, oldest entry). Every other
 * variant in the cluster has its stock added onto the survivor, then is
 * soft-deleted (ProductVariant::booted() already releases its barcode on
 * delete — the same mechanism a removed size always used). Historical
 * sale/intake/return/damage rows keep pointing at whichever variant they
 * actually happened against — nothing about the past is rewritten, only
 * which row is live and sellable going forward.
 *
 * product_variants is per-PRODUCT in scope here (variants only ever
 * belong to one product), so merging within one product never touches
 * products.stock — the total was never wrong, only split across two
 * live rows instead of one.
 */
class MergeCaseDuplicateVariants extends Command
{
    protected $signature = 'variants:merge-case-duplicates {--shop=} {--confirm}';

    protected $description = 'Merges variants that only differ by letter case/whitespace in colour+size (pre-guard duplicates). Read-only unless --confirm is passed.';

    public function handle(): int
    {
        $confirm = (bool) $this->option('confirm');
        $shopIds = $this->option('shop')
            ? [(int) $this->option('shop')]
            : Shop::withoutGlobalScopes()->pluck('id')->all();

        $totalMerges = 0;

        foreach ($shopIds as $shopId) {
            // Activity::log() reads the acting shop from Tenancy, which a
            // console run never has by default — set it explicitly so each
            // merge's log entry lands on the right shop, same as it would
            // from a real request
            \App\Support\Tenancy::set($shopId);

            // withoutGlobalScopes() strips SoftDeletingScope too — an
            // already-merged (soft-deleted) loser would otherwise keep
            // reappearing here forever, failing the idempotency this
            // command depends on for being safe to re-run
            $variants = ProductVariant::withoutGlobalScopes()->where('shop_id', $shopId)->whereNull('deleted_at')->get();
            if ($variants->isEmpty()) {
                continue;
            }

            $byProduct = $variants->groupBy('product_id');
            foreach ($byProduct as $productId => $productVariants) {
                $clusters = $productVariants->groupBy(fn ($v) => ProductVariant::cellKey($v->color, $v->size));
                foreach ($clusters as $cluster) {
                    if ($cluster->count() < 2) {
                        continue;
                    }

                    $withSales = $cluster->filter(
                        fn ($v) => SaleItem::withoutGlobalScopes()->where('product_variant_id', $v->id)->exists()
                    )->sortBy('id');
                    $survivor = $withSales->first() ?? $cluster->sortBy('id')->first();
                    $losers = $cluster->reject(fn ($v) => $v->id === $survivor->id);

                    $productName = \App\Models\Product::withoutGlobalScopes()->find($productId)?->name ?? "#{$productId}";
                    $this->line("শপ {$shopId} — {$productName} — [{$survivor->color}/{$survivor->size}]: survivor v#{$survivor->id} (stock {$survivor->stock}) <= ".
                        $losers->map(fn ($v) => "v#{$v->id} (stock {$v->stock}, color={$v->color})")->implode(', '));

                    if (! $confirm) {
                        $totalMerges++;

                        continue;
                    }

                    DB::transaction(function () use ($survivor, $losers) {
                        $lockedSurvivor = ProductVariant::withoutGlobalScopes()->whereKey($survivor->id)->lockForUpdate()->first();
                        $addedStock = 0.0;
                        foreach ($losers as $loser) {
                            $lockedLoser = ProductVariant::withoutGlobalScopes()->whereKey($loser->id)->lockForUpdate()->first();
                            if (! $lockedLoser) {
                                continue;
                            }
                            $addedStock += (float) $lockedLoser->stock;
                            $lockedLoser->update(['stock' => 0]);
                            $lockedLoser->delete(); // releases the barcode automatically (ProductVariant::booted())
                        }
                        $lockedSurvivor->increment('stock', $addedStock);

                        Activity::log(
                            'variant.mergeDuplicate',
                            "'{$lockedSurvivor->color}/{$lockedSurvivor->size}' — {$losers->count()}টা ডুপ্লিকেট ভ্যারিয়েন্ট v#{$lockedSurvivor->id}-এ মার্জ হলো (+".round($addedStock, 3).' স্টক)।',
                            $lockedSurvivor
                        );
                    });
                    $totalMerges++;
                }
            }
        }

        $this->newLine();
        if ($totalMerges === 0) {
            $this->info('কোনো case-duplicate ভ্যারিয়েন্ট পাওয়া যায়নি।');
        } elseif ($confirm) {
            $this->info("{$totalMerges}টা মার্জ সম্পন্ন হলো।");
        } else {
            $this->warn("{$totalMerges}টা মার্জ হতে পারে — এটা DRY RUN ছিল, কিছু লেখা হয়নি। আসল মার্জের জন্য --confirm দিয়ে আবার চালান।");
        }

        return self::SUCCESS;
    }
}
