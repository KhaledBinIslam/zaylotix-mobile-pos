<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Confirmed bug (see docs/FIX_PLAN.md Phase 7): InstallmentController::store()'s
 * "does this sale already have a plan" check ran as a plain exists() query
 * before the transaction, with no row to lock — two near-simultaneous
 * submits for the same sale could both pass that check before either
 * commits, double-scheduling the same due. A plain unique index (sale_id
 * is already globally unique to one shop via the sales table itself, so no
 * shop_id needed alongside it) closes the race at the one layer that
 * actually can't be raced — nullable, so plans with no sale_id (a general
 * plan not tied to a specific sale) are unaffected.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('installment_plans', function (Blueprint $table) {
            $table->unique('sale_id');
        });
    }

    public function down(): void
    {
        Schema::table('installment_plans', function (Blueprint $table) {
            $table->dropUnique(['sale_id']);
        });
    }
};
