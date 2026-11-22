<?php

namespace App\Console\Commands;

use App\Models\Product;
use App\Models\Shop;
use Illuminate\Console\Command;

/**
 * One-time repair for the bug fixed in Product::deleteBranchCopies() (see
 * its own docblock): before that fix, deleting a pooled product on a
 * BRANCH's own side never touched the master or any sibling — so a shop
 * could end up with one side of a shared product soft-deleted and the
 * other side still fully active, which is exactly what made the two
 * shops' Accounts page "Stock value" disagree (Baby Park, 2026-11-22 —
 * product #638 "B-20025" deleted on shop 81, master #637 on shop 80 still
 * live, ~15.5 lakh of stock value counted on one side and not the other).
 *
 * Now that deletion is symmetric going forward, this finds every EXISTING
 * pooled product group where that same asymmetry already happened in the
 * past and brings the whole group to the SAME state: if any member of a
 * pool group is deleted, every member becomes deleted (matching Khaled's
 * explicit decision that an owner's delete is intentional and must apply
 * everywhere, not the other way around re-animating something an owner
 * meant to remove).
 *
 * --dry-run (default without --apply): lists every mismatched group,
 * which shop(s) have it deleted vs active, and its stock value, without
 * changing anything. --apply actually performs the fix. Always run
 * --dry-run first and read the list before --apply.
 */
class ProductsReconcileDeletedPool extends Command
{
    protected $signature = 'products:reconcile-deleted-pool {--shop=} {--apply}';

    protected $description = 'Lists (or, with --apply, fixes) pooled products where one shop has it soft-deleted and a sibling shop still shows it active.';

    public function handle(): int
    {
        $shopId = $this->option('shop');
        if (! $shopId) {
            $this->error('--shop=<main shop id> দিতে হবে।');

            return self::FAILURE;
        }

        $shop = Shop::withoutGlobalScopes()->find($shopId);
        if (! $shop) {
            $this->error("শপ ID {$shopId} পাওয়া যায়নি।");

            return self::FAILURE;
        }
        if ($shop->parent_shop_id) {
            $this->error('এটা মূল শপের ID হতে হবে, শাখার না।');

            return self::FAILURE;
        }
        if (! $shop->hasFeature('shared_stock_pool')) {
            $this->error("শপ \"{$shop->name}\"-এ shared_stock_pool ফিচার চালু নেই।");

            return self::FAILURE;
        }

        $apply = (bool) $this->option('apply');

        // every product in the pool group: the main shop's own rows, plus
        // every branch copy pointing at one of them -- INCLUDING already-
        // trashed rows on both sides, since that's exactly what this
        // command needs to compare
        $mainProducts = Product::withoutGlobalScopes()->withTrashed()->where('shop_id', $shop->id)->get();
        $mainIds = $mainProducts->pluck('id');
        $branchCopies = Product::withoutGlobalScopes()->withTrashed()->whereIn('master_product_id', $mainIds)->get();

        $groups = $mainProducts->map(fn (Product $m) => $branchCopies->where('master_product_id', $m->id)->push($m));

        $mismatchCount = 0;
        $totalValueToRemove = 0.0;

        foreach ($groups as $group) {
            $deleted = $group->filter(fn (Product $p) => $p->deleted_at !== null);
            $active = $group->filter(fn (Product $p) => $p->deleted_at === null);

            if ($deleted->isEmpty() || $active->isEmpty()) {
                continue; // whole group agrees already -- nothing to do
            }

            $mismatchCount++;
            $this->line("আর্টিকেল \"{$group->first()->name}\":");
            foreach ($deleted as $p) {
                $this->line("  - মুছে ফেলা: Shop #{$p->shop_id} [{$p->name}#{$p->id}] (deleted_at={$p->deleted_at})");
            }
            foreach ($active as $p) {
                $value = $this->productValue($p);
                $totalValueToRemove += $value;
                $this->line("  - এখনো সক্রিয়: Shop #{$p->shop_id} [{$p->name}#{$p->id}] — stock value ৳".number_format($value, 2));
            }

            if ($apply) {
                foreach ($active as $p) {
                    $p->delete(); // fires Product::deleteBranchCopies() too, harmless no-op for an already-handled sibling
                    $this->info("    -> Shop #{$p->shop_id} এর কপি মুছে ফেলা হলো।");
                }
            }
        }

        $this->newLine();
        $this->info(($apply ? '' : 'প্রিভিউ (--dry-run): ')."{$mismatchCount}টা পণ্য-গ্রুপে mismatch পাওয়া গেছে।");
        $this->info('মোট stock value যা '.($apply ? 'বাদ দেওয়া হলো' : 'বাদ যাবে').': ৳'.number_format($totalValueToRemove, 2));

        if (! $apply && $mismatchCount > 0) {
            $this->warn('আসল পরিবর্তন করতে --apply যোগ করে আবার চালান।');
        }

        return self::SUCCESS;
    }

    /** Same formula as AccountsController::stockValue(), for one product at a time. */
    private function productValue(Product $p): float
    {
        $variants = \App\Models\ProductVariant::withoutGlobalScopes()->where('product_id', $p->id)->get();
        if ($variants->isNotEmpty()) {
            return (float) $variants->sum(fn ($v) => (float) ($v->cost ?? $p->cost) * (float) $v->stock);
        }

        return (float) $p->cost * (float) $p->stock;
    }
}
