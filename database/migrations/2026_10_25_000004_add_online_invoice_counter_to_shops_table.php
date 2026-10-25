<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * An online sale gets its own 'OL-' invoice series — see docs/FIX_PLAN.md's
 * Phase 32 design note. Same pattern as invoice_counter/
 * wholesale_invoice_counter/po_counter; never touches the retail/wholesale
 * series.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('shops', function (Blueprint $table) {
            $table->unsignedInteger('online_invoice_counter')->default(1000)->after('wholesale_invoice_counter');
        });
    }

    public function down(): void
    {
        Schema::table('shops', function (Blueprint $table) {
            $table->dropColumn('online_invoice_counter');
        });
    }
};
