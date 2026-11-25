<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * 'gateway' fell out of sale_payments.method.
 *
 * 2026_07_31_000013 added it (cash, bkash, nagad, gateway). Then
 * 2026_10_18_000003 (trade_credit) and 2026_10_25_000003 (cod) each rewrote
 * the whole ENUM from the previous migration's list WITHOUT it, so on MySQL
 * every insert of a 'gateway' payment has been rejected ("Data truncated for
 * column 'method'") since 2026-10-18. The POS billing popup's price preview
 * synthesises exactly such a payment, so a coupon / loyalty-points preview at
 * Liberty returned a 500 on 2026-11-25 (incidents U6QFBH, W7SJ5W) — and a real
 * bKash/gateway checkout would have failed the same way. SQLite (the test
 * database) has no ENUM, so nothing in the test suite could see it.
 *
 * The column keeps the nullability it has today (the earlier MODIFYs left it
 * nullable); only the missing value is added back.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE sale_payments MODIFY method ENUM('cash','bkash','nagad','trade_credit','cod','gateway')");
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'mysql') {
            DB::statement("DELETE FROM sale_payments WHERE method = 'gateway'");
            DB::statement("ALTER TABLE sale_payments MODIFY method ENUM('cash','bkash','nagad','trade_credit','cod')");
        }
    }
};
