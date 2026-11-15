<?php

namespace App\Support;

use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Sale;
use App\Models\Shop;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * Honest per-shop (and whole-app) resource breakdown for Hostinger shared/
 * Cloud hosting — Khaled live: "ami ekta system cai shop wise koto percent
 * use korteche, cpu storage ram shoho" — and separately, confused by
 * SystemHealthController's disk_free_space()/disk_total_space() numbers
 * ("20gb space khai pechi?" over a 70%-of-21TB figure that was always the
 * WHOLE shared host, every other Hostinger customer's usage included, not
 * this app's own).
 *
 * What's actually measurable, and what isn't:
 *  - CPU/RAM per shop: NOT measurable. Shared/Cloud hosting gives this app
 *    no per-tenant process isolation — every shop's requests run through
 *    the same PHP-FPM workers, same codebase. There is no OS-level signal
 *    to attribute back to one shop. Reporting a fake per-shop CPU/RAM
 *    percentage here would be dishonest, so this class doesn't.
 *  - Storage IS measurable, in two real, summable parts:
 *      (a) database rows — MySQL doesn't track "bytes used by shop_id=X"
 *          directly (a table's data_length is for the WHOLE table), so
 *          each shop's share of a table is ESTIMATED as that shop's
 *          fraction of the table's row count × the table's real total
 *          byte size. Summed across every table that actually has a
 *          shop_id column (found dynamically via information_schema, not
 *          a hardcoded list — a future table is picked up automatically).
 *      (b) uploaded files (product photos) — these sizes are exact,
 *          summed from the real files on disk, not estimated.
 *  - "Activity" (sales/products/users count) is reported alongside
 *    storage as the closest honest proxy for "how much this shop uses
 *    the system" where CPU/RAM can't be — explicitly labeled as activity,
 *    never implied to be a resource percentage.
 */
class ShopResourceUsage
{
    /** @return array{shops: array<int, array>, totals: array} */
    public static function perShop(): array
    {
        $dbBytesByShop = self::estimatedDatabaseBytesByShop();
        $fileBytesByShop = self::fileBytesByShop();

        $salesCounts = Sale::withoutGlobalScopes()->select('shop_id', DB::raw('COUNT(*) as cnt'))->groupBy('shop_id')->pluck('cnt', 'shop_id');
        $salesLast30d = Sale::withoutGlobalScopes()->where('created_at', '>=', now()->subDays(30))
            ->select('shop_id', DB::raw('COUNT(*) as cnt'))->groupBy('shop_id')->pluck('cnt', 'shop_id');
        $productCounts = Product::withoutGlobalScopes()->select('shop_id', DB::raw('COUNT(*) as cnt'))->groupBy('shop_id')->pluck('cnt', 'shop_id');
        $userCounts = User::withoutGlobalScopes()->select('shop_id', DB::raw('COUNT(*) as cnt'))->groupBy('shop_id')->pluck('cnt', 'shop_id');

        $totalDbBytes = array_sum($dbBytesByShop);
        $totalFileBytes = array_sum($fileBytesByShop);
        $totalBytes = $totalDbBytes + $totalFileBytes;

        $shops = Shop::withoutGlobalScopes()->orderBy('id')->get(['id', 'name', 'phone']);

        $rows = $shops->map(function ($shop) use ($dbBytesByShop, $fileBytesByShop, $salesCounts, $salesLast30d, $productCounts, $userCounts, $totalBytes) {
            $db = $dbBytesByShop[$shop->id] ?? 0.0;
            $files = $fileBytesByShop[$shop->id] ?? 0.0;
            $total = $db + $files;

            return [
                'id' => $shop->id,
                'name' => $shop->name,
                'phone' => $shop->phone,
                'db_mb' => round($db / 1048576, 2),
                'file_mb' => round($files / 1048576, 2),
                'total_mb' => round($total / 1048576, 2),
                'percent_of_total' => $totalBytes > 0 ? round($total / $totalBytes * 100, 2) : 0.0,
                'sales_count' => (int) ($salesCounts[$shop->id] ?? 0),
                'sales_last_30d' => (int) ($salesLast30d[$shop->id] ?? 0),
                'product_count' => (int) ($productCounts[$shop->id] ?? 0),
                'user_count' => (int) ($userCounts[$shop->id] ?? 0),
            ];
        })->sortByDesc('total_mb')->values()->all();

        return [
            'shops' => $rows,
            'totals' => [
                'db_mb' => round($totalDbBytes / 1048576, 2),
                'file_mb' => round($totalFileBytes / 1048576, 2),
                'total_mb' => round($totalBytes / 1048576, 2),
            ],
        ];
    }

    /** Whole-app totals only — what SystemHealthController shows instead of the meaningless whole-shared-host disk figure. */
    public static function appTotals(): array
    {
        $dbBytes = array_sum(self::estimatedDatabaseBytesByShop());
        $fileBytes = array_sum(self::fileBytesByShop());

        return [
            'db_mb' => round($dbBytes / 1048576, 2),
            'file_mb' => round($fileBytes / 1048576, 2),
            'total_mb' => round(($dbBytes + $fileBytes) / 1048576, 2),
        ];
    }

    /** @return array<int, float> shop_id => estimated bytes */
    private static function estimatedDatabaseBytesByShop(): array
    {
        // information_schema.tables'/.columns' (data_length, index_length,
        // table_schema = DATABASE()) are MySQL-only syntax — production
        // always runs MySQL, but the test suite runs SQLite (phpunit.xml),
        // which has no such schema at all. Reporting 0 database bytes
        // there is correct (not a crash): SQLite's own file-level size
        // isn't split per-table the same way, and no test here asserts a
        // real db_mb figure — only file bytes and activity counts, which
        // this guard doesn't touch.
        if (DB::getDriverName() !== 'mysql') {
            return [];
        }

        $tableSizes = collect(DB::select(
            'SELECT table_name, (data_length + index_length) AS bytes FROM information_schema.tables WHERE table_schema = DATABASE()'
        ))->keyBy(fn ($row) => $row->table_name ?? $row->TABLE_NAME);

        $shopIdTables = collect(DB::select(
            "SELECT DISTINCT table_name FROM information_schema.columns WHERE table_schema = DATABASE() AND column_name = 'shop_id'"
        ))->map(fn ($row) => $row->table_name ?? $row->TABLE_NAME);

        $bytesByShop = [];
        foreach ($shopIdTables as $table) {
            $sizeRow = $tableSizes->get($table);
            $totalBytes = (float) ($sizeRow->bytes ?? $sizeRow->BYTES ?? 0);
            if ($totalBytes <= 0) {
                continue;
            }

            // a table a migration created but nothing has ever written to
            // (or one this host's version of information_schema doesn't
            // report row-level stats for) just contributes 0 — never a
            // division error
            try {
                $rowCounts = DB::table($table)->whereNotNull('shop_id')
                    ->select('shop_id', DB::raw('COUNT(*) as cnt'))->groupBy('shop_id')->get();
            } catch (\Throwable) {
                continue;
            }

            $totalRows = $rowCounts->sum('cnt');
            if ($totalRows <= 0) {
                continue;
            }

            foreach ($rowCounts as $row) {
                $bytesByShop[$row->shop_id] = ($bytesByShop[$row->shop_id] ?? 0.0) + ($totalBytes * $row->cnt / $totalRows);
            }
        }

        return $bytesByShop;
    }

    /** @return array<int, float> shop_id => real bytes on disk (product + variant photos) */
    private static function fileBytesByShop(): array
    {
        $bytesByShop = [];

        foreach (Product::withoutGlobalScopes()->whereNotNull('photo_path')->get(['id', 'shop_id', 'photo_path']) as $p) {
            if (Storage::disk('public')->exists($p->photo_path)) {
                $bytesByShop[$p->shop_id] = ($bytesByShop[$p->shop_id] ?? 0.0) + Storage::disk('public')->size($p->photo_path);
            }
        }

        foreach (ProductVariant::withoutGlobalScopes()->whereNotNull('photo_path')->get(['id', 'shop_id', 'photo_path']) as $v) {
            if (Storage::disk('public')->exists($v->photo_path)) {
                $bytesByShop[$v->shop_id] = ($bytesByShop[$v->shop_id] ?? 0.0) + Storage::disk('public')->size($v->photo_path);
            }
        }

        return $bytesByShop;
    }
}
