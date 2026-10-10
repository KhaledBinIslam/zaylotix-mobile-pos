<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * `returns.qty` was `integer` since the table's creation, even though
 * ReturnController::store() has always validated it as `numeric` (min:0.001)
 * to support weighed-product returns — a fractional return (e.g. 0.5kg) was
 * silently truncated to 0 on save. Raw SQL, not Schema::table()->change(),
 * since this project has no doctrine/dbal installed (confirmed: only
 * doctrine/inflector and doctrine/lexer exist under vendor/doctrine).
 *
 * khaled: ব্যাকআপ রাখার কথা মনে করিয়ে দেওয়া হলো — এটা চালানোর আগে DB ব্যাকআপ নিন
 * (deploy command এ ব্যাকআপ কমান্ডও আলাদাভাবে দেওয়া হবে)।
 */
return new class extends Migration
{
    public function up(): void
    {
        // sqlite (the test suite's DB) has no real column types — any
        // value already fits regardless of the declared type — so this
        // only needs to actually run on mysql, same guard already used by
        // 2026_07_31_000008_add_restaurant_split_bill_and_extras.php.
        if (DB::getDriverName() === 'mysql') {
            DB::statement('ALTER TABLE returns MODIFY qty DECIMAL(12,3) NOT NULL DEFAULT 0');
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'mysql') {
            DB::statement('ALTER TABLE returns MODIFY qty INT NOT NULL DEFAULT 0');
        }
    }
};
