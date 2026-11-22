<?php

namespace App\Console\Commands;

use App\Models\Product;
use App\Models\Shop;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * One-time cleanup for the real incident found 2026-11-22: two demo shops
 * (383 "ডেমো শপ - জুতা", 384 "ডেমো শপ - ভ্যারাইটি") were cloned from real
 * paying shops (80 Baby Park, 157 SEAMAN) for sales-demo purposes, but
 * their products kept master_product_id pointing at the real shops' real
 * products -- 211 rows platform-wide. Confirmed live contamination: real
 * sales inside the demo shop decremented Baby Park's real pooled stock,
 * and editing Baby Park's stock pushed values into the demo shop's copy.
 *
 * This does NOT delete or restore anything, and does NOT touch stock
 * values -- it only severs the accidental link (master_product_id -> NULL)
 * on the OFFENDING side (the demo shop's own products), so each becomes a
 * fully independent product again, exactly as any other demo shop's
 * products already are. There is no separate variant-level link column to
 * clear -- ProductVariant::stockPoolMemberIds() always resolves through
 * its parent Product's own master_product_id first (see Product.php), so
 * nulling the product's column alone fully and immediately stops variant
 * pooling for it too.
 *
 * Scope, exactly matching Product::validBranchCopyIds()'s own definition
 * of a genuine pool sibling (the fix this cleanup is downstream of): a
 * product is contaminated if master_product_id is set AND its own shop's
 * parent_shop_id does not match that master's shop_id (covers both "not a
 * branch at all" and "a branch of some OTHER shop") OR its shop's
 * account_type is 'demo'.
 *
 * --dry-run (default without --apply): exports every affected row to CSV
 * and prints the list, changes nothing. --apply performs the actual
 * UPDATE after the same CSV export (so the pre-image is always on disk
 * first). Take a full `php artisan zaylotix:backup` before --apply
 * regardless -- this only protects the specific rows touched, not a
 * substitute for a real backup.
 */
class ProductsUnlinkDemoPoolContamination extends Command
{
    protected $signature = 'products:unlink-demo-pool-contamination {--apply}';

    protected $description = 'Finds (or, with --apply, fixes) products whose master_product_id points into a real shop despite not being a genuine branch of it. Always exports a CSV pre-image first.';

    public function handle(): int
    {
        $apply = (bool) $this->option('apply');

        $rows = DB::table('products as p')
            ->join('products as m', 'm.id', '=', 'p.master_product_id')
            ->join('shops as ps', 'ps.id', '=', 'p.shop_id')
            ->join('shops as ms', 'ms.id', '=', 'm.shop_id')
            ->whereNotNull('p.master_product_id')
            ->where(function ($q) {
                $q->whereNull('ps.parent_shop_id')
                    ->orWhereColumn('ps.parent_shop_id', '!=', 'ms.id')
                    ->orWhere('ps.account_type', 'demo');
            })
            ->select(
                'p.id as product_id', 'p.shop_id as product_shop_id', 'ps.name_en as product_shop_name', 'ps.account_type as product_shop_account_type',
                'p.name as product_name', 'p.master_product_id', 'm.shop_id as master_shop_id', 'ms.name_en as master_shop_name'
            )
            ->get();

        if ($rows->isEmpty()) {
            $this->info('কোনো contaminated লিঙ্ক পাওয়া যায়নি।');

            return self::SUCCESS;
        }

        $csvPath = storage_path('app/private/demo-pool-contamination-'.now()->format('Y-m-d_His').'.csv');
        $fh = fopen($csvPath, 'w');
        fputcsv($fh, array_keys((array) $rows->first()));
        foreach ($rows as $r) {
            fputcsv($fh, (array) $r);
        }
        fclose($fh);
        $this->info("প্রি-ইমেজ এক্সপোর্ট করা হলো: {$csvPath} ({$rows->count()} রো)");

        $byShop = $rows->groupBy('product_shop_id');
        foreach ($byShop as $shopId => $group) {
            $this->line("Shop #{$shopId} ({$group->first()->product_shop_name}, account_type={$group->first()->product_shop_account_type}): {$group->count()} টা product bhul kore shop #{$group->first()->master_shop_id} ({$group->first()->master_shop_name})-er shathe linked");
        }

        if ($apply) {
            $ids = $rows->pluck('product_id');
            Product::withoutGlobalScopes()->whereIn('id', $ids)->update(['master_product_id' => null]);
            $this->info("{$ids->count()}টা product-er master_product_id NULL kora holo।");
        } else {
            $this->warn('আসল পরিবর্তন করতে --apply যোগ করে আবার চালান।');
        }

        return self::SUCCESS;
    }
}
