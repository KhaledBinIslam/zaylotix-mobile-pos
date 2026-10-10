<?php

namespace App\Console\Commands;

use App\Models\Product;
use App\Models\Shop;
use App\Models\SalesReturn;
use Illuminate\Console\Command;

/**
 * Read-only report for the Reports::rangeStats profit fix (full refund
 * subtracted vs just the refund-minus-cost portion). Old return rows (saved
 * before the `cost` column existed) have cost = NULL and still use the OLD
 * formula — this command estimates what backfilling them with each
 * product's CURRENT cost would do to historical net profit, per shop, so
 * Khaled can decide whether that backfill is worth doing before running one.
 * Never writes anything.
 */
class ReturnsProfitImpact extends Command
{
    protected $signature = 'returns:profit-impact';

    protected $description = 'Read-only: per-shop breakdown of old (cost=NULL) return rows and the estimated net-profit increase if backfilled with current product cost.';

    public function handle(): int
    {
        $oldRows = SalesReturn::withoutGlobalScopes()->whereNull('cost')->get();

        if ($oldRows->isEmpty()) {
            $this->info('কোনো পুরোনো (cost=NULL) return সারি পাওয়া যায়নি — backfill করার কিছু নেই।');

            return self::SUCCESS;
        }

        $productCosts = Product::withoutGlobalScopes()
            ->whereIn('id', $oldRows->pluck('product_id')->unique())
            ->pluck('cost', 'id');

        $shopNames = Shop::withoutGlobalScopes()->whereIn('id', $oldRows->pluck('shop_id')->unique())->pluck('name', 'id');

        $rows = [];
        $grandCount = 0;
        $grandRefund = 0.0;
        $grandEstCost = 0.0;

        foreach ($oldRows->groupBy('shop_id') as $shopId => $group) {
            $count = $group->count();
            $totalRefund = (float) $group->sum('refund');
            $estCost = (float) $group->sum(function (SalesReturn $r) use ($productCosts) {
                return (float) $r->qty * (float) ($productCosts->get($r->product_id) ?? 0);
            });

            $rows[] = [
                $shopNames->get($shopId, "shop#{$shopId}"),
                $count,
                number_format($totalRefund, 2),
                number_format($estCost, 2),
                number_format($estCost, 2), // net profit increase if backfilled = the cost that stops being subtracted
            ];

            $grandCount += $count;
            $grandRefund += $totalRefund;
            $grandEstCost += $estCost;
        }

        $this->table(
            ['Shop', 'Old return count', 'Total refund (৳)', 'Est. cost (current product cost × qty)', 'Net profit increase if backfilled (৳)'],
            $rows
        );

        $this->newLine();
        $this->info("সর্বমোট: {$grandCount} পুরোনো return সারি, মোট refund ৳".number_format($grandRefund, 2).', backfill করলে net profit বাড়বে আনুমানিক ৳'.number_format($grandEstCost, 2).' (বর্তমান পণ্যের cost দিয়ে হিসাব করা — বিক্রির সময়ের আসল cost নয়, যেহেতু পুরোনো সারিগুলোতে কোনো sale_item link নেই)।');
        $this->info('এই কমান্ড কিছু পরিবর্তন করেনি — শুধু রিপোর্ট দেখিয়েছে। Backfill করতে চাইলে জানান, আলাদা কমান্ড বানিয়ে দেব।');

        return self::SUCCESS;
    }
}
