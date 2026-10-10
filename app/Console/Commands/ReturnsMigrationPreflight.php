<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Read-only preflight for the 5 new return/exchange migrations
 * (2026_10_10_000002 through 000006). Reports current row counts and the
 * current returns.qty column type so Khaled can see exactly what's about to
 * be touched before running `php artisan migrate --force` live. Never
 * writes anything.
 */
class ReturnsMigrationPreflight extends Command
{
    protected $signature = 'returns:migration-preflight';

    protected $description = 'Read-only: shows current state of returns/sales/shops/damages tables before running the return/exchange migrations.';

    public function handle(): int
    {
        $this->info('--- Migration preflight (read-only, কিছু পরিবর্তন করা হয়নি) ---');
        $this->newLine();

        $returnsCount = DB::table('returns')->count();
        $this->line("returns টেবিলে মোট সারি: {$returnsCount}");

        $qtyColumn = DB::selectOne("SHOW COLUMNS FROM returns WHERE Field = 'qty'");
        $this->line('returns.qty কলামের বর্তমান টাইপ: '.($qtyColumn->Type ?? 'unknown'));

        $maxQty = DB::table('returns')->max('qty');
        $this->line('returns.qty সর্বোচ্চ মান (migration এর পর এটাই decimal হবে): '.($maxQty ?? 'N/A'));

        $this->newLine();

        $saleTypeColumn = DB::selectOne("SHOW COLUMNS FROM sales WHERE Field = 'sale_type'");
        $this->line('sales.sale_type কলামের বর্তমান টাইপ: '.($saleTypeColumn->Type ?? 'unknown'));

        $distinctSaleTypes = DB::table('sales')->distinct()->pluck('sale_type')->implode(', ');
        $this->line('sales.sale_type এ এখন যা আছে: '.$distinctSaleTypes);

        $this->newLine();

        $shopsCount = DB::table('shops')->count();
        $hasReturnWindowCol = Schema::hasColumn('shops', 'return_window_days');
        $this->line("shops টেবিলে মোট দোকান: {$shopsCount}, return_window_days কলাম আগে থেকে আছে কিনা: ".($hasReturnWindowCol ? 'হ্যাঁ (migration আগেই চলেছে)' : 'না'));

        $damagesCount = DB::table('damages')->count();
        $hasVariantCol = Schema::hasColumn('damages', 'product_variant_id');
        $this->line("damages টেবিলে মোট সারি: {$damagesCount}, product_variant_id কলাম আগে থেকে আছে কিনা: ".($hasVariantCol ? 'হ্যাঁ (migration আগেই চলেছে)' : 'না'));

        $this->newLine();
        $this->info('এই কমান্ড শুধু রিপোর্ট দেখিয়েছে, কিছু পরিবর্তন করেনি। returns.qty কলাম change করার আগে DB ব্যাকআপ নিয়ে নিন।');

        return self::SUCCESS;
    }
}
