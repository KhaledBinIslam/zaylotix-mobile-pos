<?php

namespace App\Console\Commands;

use App\Models\ActivityLog;
use App\Models\Product;
use App\Models\Shop;
use App\Support\CatalogSync;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;

/**
 * One-time fix (2026-11-23, Baby Park stock-value parity, "item 3"):
 * products baby #2296, Waterproof #2297 and waterproof #2298 were created
 * directly at the branch (shop 81) before branch-side creations learned
 * to lift themselves into the pool, so they exist nowhere else. Runs each
 * through CatalogSync::syncOneProduct() — the exact engine every new
 * branch-side creation now goes through — which creates the master at
 * shop 80 with the SAME stock figure and links the branch row to it.
 * CSV pre-image first; refuses any row whose live state no longer matches
 * the approved plan.
 */
class ProductsAdoptShop81Orphans extends Command
{
    protected $signature = 'products:adopt-shop81-orphans {--apply}';

    protected $description = 'One-time: lift shop 81\'s three branch-only products (#2296, #2297, #2298) into the pool with a master at shop 80 (dry-run unless --apply)';

    private const EXPECTED = [2296 => 'baby', 2297 => 'Waterproof', 2298 => 'waterproof'];

    public function handle(): int
    {
        $apply = (bool) $this->option('apply');

        $main = Shop::withoutGlobalScopes()->find(80);
        $branch = Shop::withoutGlobalScopes()->find(81);
        if (! $main || ! $branch || (int) $branch->parent_shop_id !== 80
            || ! $main->hasFeature('shared_catalog') || ! $main->hasFeature('shared_stock_pool')) {
            $this->error('REFUSE: shops 80/81 are not the pooled pair this fix was approved for.');

            return self::FAILURE;
        }

        $rows = [];
        foreach (self::EXPECTED as $id => $name) {
            $p = Product::withoutGlobalScopes()->find($id);
            if (! $p || $p->shop_id !== 81 || $p->deleted_at !== null || $p->master_product_id !== null || $p->name !== $name) {
                $this->error("REFUSE #{$id}: expected live, unlinked '{$name}' at shop 81 — found ".($p ? "shop {$p->shop_id}, name '{$p->name}', master ".($p->master_product_id ?? 'NULL').', deleted_at '.($p->deleted_at ?? 'NULL') : 'no row'));

                return self::FAILURE;
            }
            $this->line(sprintf('#%d %s stock=%s cost=%s -> master will be created at shop 80 with the same figure', $p->id, $p->name, $p->stock, $p->cost));
            $rows[] = $p;
        }

        if (! $apply) {
            $this->info('Dry-run only. Re-run with --apply to execute.');

            return self::SUCCESS;
        }

        $csvPath = storage_path('app/private/backups/shop81-orphan-adopt-'.now()->format('Y-m-d_His').'.csv');
        File::ensureDirectoryExists(dirname($csvPath));
        $fh = fopen($csvPath, 'w');
        fputcsv($fh, array_keys($rows[0]->getAttributes()));
        foreach ($rows as $row) {
            fputcsv($fh, array_values($row->getAttributes()));
        }
        fclose($fh);
        $this->info("Pre-image: {$csvPath}");

        DB::transaction(function () use ($rows, $main, $branch) {
            foreach ($rows as $row) {
                CatalogSync::syncOneProduct($branch, $row);
                $masterId = $row->fresh()->master_product_id;

                foreach ([80, 81] as $shopId) {
                    ActivityLog::create([
                        'shop_id' => $shopId,
                        'user_id' => null,
                        'action' => 'product.manual_pool_adopt',
                        'subject_type' => Product::class,
                        'subject_id' => $shopId === 80 ? $masterId : $row->id,
                        'description' => "ম্যানুয়াল ফিক্স: শাখায় তৈরি পণ্য '{$row->name}' (#{$row->id}) মূল শপের সাথে পুল-লিংক করা হলো।",
                        'meta' => ['command' => 'products:adopt-shop81-orphans --apply', 'branch_product_id' => $row->id, 'master_product_id' => $masterId],
                        'created_at' => now(),
                    ]);
                }

                $this->info("Adopted #{$row->id} {$row->name} -> master #{$masterId} at shop 80");
            }
        });

        return self::SUCCESS;
    }
}
