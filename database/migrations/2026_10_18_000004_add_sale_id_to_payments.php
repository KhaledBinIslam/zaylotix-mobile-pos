<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 3.2 (see docs/FIX_PLAN.md) — customers.due is, and remains, a single
 * running total; this does not change that. It only lets a payment going
 * forward optionally say which specific invoice it's paying down, so
 * PaymentController::invoices() can show a trustworthy per-invoice picture
 * for anything collected from here on. Nullable and backfill-impossible for
 * every payment collected before this — those stay untagged, exactly as
 * they already are today, and nothing about how customers.due itself is
 * computed or displayed changes.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->foreignId('sale_id')->nullable()->after('customer_id')->constrained('sales')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->dropConstrainedForeignId('sale_id');
        });
    }
};
