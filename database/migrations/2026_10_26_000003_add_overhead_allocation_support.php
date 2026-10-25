<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * "Online Business" module, part 5 — see docs/FIX_PLAN.md's Phase 35
 * design note. `overhead_allocation_rule` null (every shop before
 * this feature) means zero overhead is allocated to any channel —
 * profit_after_overhead == profit_before_overhead until the owner
 * opts in. `overhead_allocation_percent` is only read when the rule
 * is 'manual'.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('shops', function (Blueprint $table) {
            $table->string('overhead_allocation_rule')->nullable()->after('return_window_days');
        });

        Schema::table('sales_channels', function (Blueprint $table) {
            $table->decimal('overhead_allocation_percent', 5, 2)->nullable()->after('gateway_fee_percent');
        });
    }

    public function down(): void
    {
        Schema::table('shops', function (Blueprint $table) {
            $table->dropColumn('overhead_allocation_rule');
        });

        Schema::table('sales_channels', function (Blueprint $table) {
            $table->dropColumn('overhead_allocation_percent');
        });
    }
};
