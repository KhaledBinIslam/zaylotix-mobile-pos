<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 4 "full Wholesale system", part C — separate wholesale invoice
 * numbering (see docs/FIX_PLAN.md's design note). Same pattern as
 * invoice_counter/quotation_counter/po_counter — locked under the same
 * Shop::whereKey($id)->lockForUpdate() checkout already takes, so this
 * never collides with the retail series.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('shops', function (Blueprint $table) {
            $table->unsignedInteger('wholesale_invoice_counter')->default(1000)->after('po_counter');
        });
    }

    public function down(): void
    {
        Schema::table('shops', function (Blueprint $table) {
            $table->dropColumn('wholesale_invoice_counter');
        });
    }
};
