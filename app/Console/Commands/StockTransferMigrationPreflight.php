<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Read-only preflight for the 2026_10_11_000001 migration (adds nullable
 * from_product_variant_id/to_product_variant_id/variant_label to
 * stock_transfers). Reports current row count and whether those columns
 * already exist. Never writes anything.
 */
class StockTransferMigrationPreflight extends Command
{
    protected $signature = 'stock-transfers:migration-preflight';

    protected $description = 'Read-only: shows current state of stock_transfers before running the variant-support migration.';

    public function handle(): int
    {
        $this->info('--- Migration preflight (read-only, কিছু পরিবর্তন করা হয়নি) ---');
        $this->newLine();

        $count = DB::table('stock_transfers')->count();
        $this->line("stock_transfers টেবিলে মোট সারি: {$count}");

        foreach (['from_product_variant_id', 'to_product_variant_id', 'variant_label'] as $col) {
            $exists = DB::table('information_schema.columns')
                ->where('table_schema', DB::getDatabaseName())
                ->where('table_name', 'stock_transfers')
                ->where('column_name', $col)
                ->exists();
            $this->line("কলাম '{$col}' আগে থেকে আছে কিনা: ".($exists ? 'হ্যাঁ (migration আগেই চলেছে)' : 'না'));
        }

        $this->newLine();
        $this->info('এই কমান্ড শুধু রিপোর্ট দেখিয়েছে, কিছু পরিবর্তন করেনি।');

        return self::SUCCESS;
    }
}
