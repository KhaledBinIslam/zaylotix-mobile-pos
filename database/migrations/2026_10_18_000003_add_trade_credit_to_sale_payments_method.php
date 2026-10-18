<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Adds 'trade_credit' as a fourth sale_payments.method value — used only by
 * ReturnController::createExchangeSale() to record the value of a traded-in
 * item as its own tender line on the replacement Sale, so
 * sum(sale_payments) + due nets out to the sale's (gross) total correctly.
 * Before this, the exchange sale's net_settlement (what was actually still
 * owed after the trade-in credit) was computed correctly in memory but
 * never persisted anywhere — every later read (invoice view, printed/
 * WhatsApp memo, the installment-plan-seeding flow) recomputed "due" as
 * `total - tendered`, which ignored the credit entirely and overstated due
 * by exactly that amount (see docs/FIX_PLAN.md Phase 3.1).
 *
 * Raw SQL to modify the enum's value list — same pattern already used by
 * 2026_10_10_000004_add_exchange_to_sales_sale_type.php.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE sale_payments MODIFY method ENUM('cash','bkash','nagad','trade_credit')");
        } elseif (DB::getDriverName() === 'sqlite') {
            DB::statement('PRAGMA ignore_check_constraints = ON');
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'mysql') {
            DB::statement("DELETE FROM sale_payments WHERE method = 'trade_credit'");
            DB::statement("ALTER TABLE sale_payments MODIFY method ENUM('cash','bkash','nagad')");
        }
    }
};
