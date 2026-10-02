<?php

namespace App\Jobs;

use App\Exports\ShopDataExport;
use App\Models\Shop;
use App\Models\ShopBackup;
use App\Support\ShopSqlDump;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Maatwebsite\Excel\Facades\Excel;

/**
 * Builds one shop owner's own self-service backup in the background — the
 * first real use of this app's database queue (previously configured but
 * never dispatched to, see the production-readiness audit). Reuses the
 * same ShopSqlDump/ShopDataExport logic the admin-only per-shop export
 * already relies on, so there is exactly one place that knows which
 * tables belong to a shop, not two.
 */
class GenerateShopBackupJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public function __construct(public ShopBackup $shopBackup) {}

    public function handle(): void
    {
        $backup = $this->shopBackup;
        $backup->update(['status' => 'processing']);

        try {
            $shop = Shop::findOrFail($backup->shop_id);
            $slug = Str::slug($shop->name) ?: 'shop';
            $dir = "shop-backups/{$shop->id}";

            if ($backup->format === 'xlsx') {
                $path = "{$dir}/{$slug}-backup-{$backup->id}.xlsx";
                Excel::store(new ShopDataExport($shop), $path, 'local');
            } else {
                $path = "{$dir}/{$slug}-backup-{$backup->id}.sql";
                Storage::disk('local')->put($path, ShopSqlDump::generate($shop));
            }

            $backup->update(['status' => 'done', 'file_path' => $path, 'completed_at' => now()]);
        } catch (\Throwable $e) {
            // a bad/oversized shop's backup must never take the queue
            // runner down or leave a stuck "processing" row behind
            $backup->update(['status' => 'failed', 'failed_reason' => $e->getMessage()]);
        }
    }
}
