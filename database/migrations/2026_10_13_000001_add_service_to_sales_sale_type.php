<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Adds 'service' as a sales.sale_type value — the Sale created when a
 * service/repair ticket is billed (see ServiceTicketController::bill()).
 * Counted completely normally everywhere (revenue, profit, bill count,
 * visits) — unlike 'exchange', a repair bill is a genuinely new
 * transaction, not a continuation of an existing one, so nothing in
 * Reports.php needs to special-case it.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE sales MODIFY sale_type ENUM('retail','wholesale','exchange','service') DEFAULT 'retail'");
        } elseif (DB::getDriverName() === 'sqlite') {
            DB::statement('PRAGMA ignore_check_constraints = ON');
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'mysql') {
            DB::statement("UPDATE sales SET sale_type = 'retail' WHERE sale_type = 'service'");
            DB::statement("ALTER TABLE sales MODIFY sale_type ENUM('retail','wholesale','exchange') DEFAULT 'retail'");
        }
    }
};
