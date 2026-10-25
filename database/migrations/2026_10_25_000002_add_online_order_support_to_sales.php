<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * "Online Business" module, part 2 (see docs/FIX_PLAN.md's Phase 32
 * design note) — an online order's Sale is created immediately (real
 * stock decrement, same as POS) but excluded from every report until
 * Delivered. `counted_in_reports` defaults true for every existing and
 * future non-online sale, so this changes nothing for them.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sales', function (Blueprint $table) {
            $table->boolean('counted_in_reports')->default(true)->after('sale_type');
        });

        if (DB::getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE sales MODIFY sale_type ENUM('retail','wholesale','exchange','service','online') DEFAULT 'retail'");
        } elseif (DB::getDriverName() === 'sqlite') {
            DB::statement('PRAGMA ignore_check_constraints = ON');
        }
    }

    public function down(): void
    {
        Schema::table('sales', function (Blueprint $table) {
            $table->dropColumn('counted_in_reports');
        });

        if (DB::getDriverName() === 'mysql') {
            DB::statement("UPDATE sales SET sale_type = 'retail' WHERE sale_type = 'online'");
            DB::statement("ALTER TABLE sales MODIFY sale_type ENUM('retail','wholesale','exchange','service') DEFAULT 'retail'");
        }
    }
};
