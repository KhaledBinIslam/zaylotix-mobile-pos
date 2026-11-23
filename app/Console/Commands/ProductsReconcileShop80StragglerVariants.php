<?php

namespace App\Console\Commands;

use App\Models\ActivityLog;
use App\Models\ProductVariant;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * One-time fix (2026-11-23, Baby Park stock-value parity, approved plan
 * "item 1+2"): variants #1391 (L-10016, 225/CK) and #10250 (80001,
 * 38-39/PURPLE BLACK) are still live at shop 80 while their shop-81
 * siblings (#1420, #10249) were deliberately deleted by the owner before
 * the variant-cascade fix existed — pre-fix-era stragglers. Soft-deletes
 * the shop-80 side through the normal model delete (so the now-deployed
 * cascade hooks run; they no-op here since the sibling is already gone)
 * and writes an explicit activity log per shop, with a CSV pre-image
 * first. Refuses each row whose live state no longer matches what the
 * approved plan was based on.
 */
class ProductsReconcileShop80StragglerVariants extends Command
{
    protected $signature = 'products:reconcile-shop80-straggler-variants {--apply}';

    protected $description = 'One-time: soft-delete shop 80 variants #1391 and #10250 whose shop-81 siblings were already deleted (dry-run unless --apply)';

    /** [shop-80 variant id => its already-deleted shop-81 sibling id] */
    private const PAIRS = [1391 => 1420, 10250 => 10249];

    public function handle(): int
    {
        $apply = (bool) $this->option('apply');
        $rows = [];

        foreach (self::PAIRS as $liveId => $deletedSiblingId) {
            $live = ProductVariant::withoutGlobalScopes()->find($liveId);
            $sibling = ProductVariant::withoutGlobalScopes()->find($deletedSiblingId);

            if (! $live || $live->shop_id !== 80 || $live->deleted_at !== null) {
                $this->error("REFUSE #{$liveId}: expected live at shop 80 — found ".($live ? "shop {$live->shop_id}, deleted_at ".($live->deleted_at ?? 'NULL') : 'no row'));

                return self::FAILURE;
            }
            if (! $sibling || $sibling->shop_id !== 81 || $sibling->deleted_at === null) {
                $this->error("REFUSE #{$liveId}: expected sibling #{$deletedSiblingId} deleted at shop 81 — state changed since the approved plan");

                return self::FAILURE;
            }

            $this->line(sprintf('#%d shop80 %s/%s stock=%s cost=%s -> soft-delete (sibling #%d deleted %s)',
                $live->id, $live->size, $live->color, $live->stock, $live->cost, $sibling->id, $sibling->deleted_at));
            $rows[] = $live;
        }

        if (! $apply) {
            $this->info('Dry-run only. Re-run with --apply to execute.');

            return self::SUCCESS;
        }

        $csvPath = storage_path('app/private/backups/shop80-straggler-variants-'.now()->format('Y-m-d_His').'.csv');
        \Illuminate\Support\Facades\File::ensureDirectoryExists(dirname($csvPath));
        $fh = fopen($csvPath, 'w');
        fputcsv($fh, array_keys($rows[0]->getAttributes()));
        foreach ($rows as $row) {
            fputcsv($fh, array_values($row->getAttributes()));
        }
        fclose($fh);
        $this->info("Pre-image: {$csvPath}");

        DB::transaction(function () use ($rows) {
            foreach ($rows as $row) {
                $row->delete();

                foreach ([80, 81] as $shopId) {
                    ActivityLog::create([
                        'shop_id' => $shopId,
                        'user_id' => null,
                        'action' => 'variant.manual_pool_reconcile',
                        'subject_type' => ProductVariant::class,
                        'subject_id' => $row->id,
                        'description' => "ম্যানুয়াল ফিক্স: শাখায় আগেই মুছে ফেলা ভ্যারিয়েন্টের মূল-শপ কপি (#{$row->id}, {$row->size}/{$row->color}) সমতায় আনতে মুছে ফেলা হলো।",
                        'meta' => ['command' => 'products:reconcile-shop80-straggler-variants --apply', 'sibling_id' => self::PAIRS[$row->id]],
                        'created_at' => now(),
                    ]);
                }

                $this->info("Deleted #{$row->id}");
            }
        });

        return self::SUCCESS;
    }
}
