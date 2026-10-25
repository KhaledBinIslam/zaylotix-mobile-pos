<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Adds 'cod' as a sale_payments.method value — see docs/FIX_PLAN.md's
 * Phase 32 design note. Deliberately never bumps cash_balance/
 * bank_balance when recorded (OnlineOrderController) or reversed
 * (SaleReversal) — that money is with the courier, not the shop, until
 * Part 3's COD settlement actually reconciles it.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE sale_payments MODIFY method ENUM('cash','bkash','nagad','trade_credit','cod')");
        } elseif (DB::getDriverName() === 'sqlite') {
            DB::statement('PRAGMA ignore_check_constraints = ON');
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'mysql') {
            DB::statement("DELETE FROM sale_payments WHERE method = 'cod'");
            DB::statement("ALTER TABLE sale_payments MODIFY method ENUM('cash','bkash','nagad','trade_credit')");
        }
    }
};
