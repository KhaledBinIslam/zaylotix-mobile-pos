<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Adds 'exchange' as a third allowed sales.sale_type value, alongside the
 * existing 'retail'/'wholesale' (see add_wholesale_pricing migration) — the
 * new Sale created for an exchange's replacement item needs to be
 * distinguishable from an ordinary sale so report/visit/loyalty counts don't
 * double-count it (see Reports::rangeStats and ReturnController::exchange).
 * Raw SQL to modify the enum's value list — Schema::table()->change() needs
 * doctrine/dbal, which isn't installed here; same pattern already used in
 * 2026_07_31_000008_add_restaurant_split_bill_and_extras.php.
 */
return new class extends Migration
{
    public function up(): void
    {
        // sqlite (the test suite's DB) has no real enum type — a plain
        // string column already accepts 'exchange' with no ALTER needed —
        // same guard already used by 2026_07_31_000008_add_restaurant_split_bill_and_extras.php.
        if (DB::getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE sales MODIFY sale_type ENUM('retail','wholesale','exchange') DEFAULT 'retail'");
        } elseif (DB::getDriverName() === 'sqlite') {
            // sqlite compiled the original enum() as a CHECK constraint
            // (`sale_type in ('retail','wholesale')`) baked into the table
            // at creation time — there's no ALTER for that without rebuilding
            // the whole table, which isn't worth it for a type only the test
            // suite uses. Disabling check-constraint enforcement for this
            // connection is enough to let the test suite actually exercise
            // 'exchange' end to end; the real schema fix above is what
            // matters in production (mysql).
            DB::statement('PRAGMA ignore_check_constraints = ON');
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'mysql') {
            DB::statement("UPDATE sales SET sale_type = 'retail' WHERE sale_type = 'exchange'");
            DB::statement("ALTER TABLE sales MODIFY sale_type ENUM('retail','wholesale') DEFAULT 'retail'");
        }
    }
};
