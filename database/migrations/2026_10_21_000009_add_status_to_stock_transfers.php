<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 4 item #1, part B — "ব্রাঞ্চ ট্রান্সফার accept/reject" (see
 * docs/FIX_PLAN.md's design note). Defaults to 'completed' so every
 * existing row, and every new transfer for a shop without the opt-in
 * transfer_confirmation feature, behaves exactly as before — instant,
 * no pending step. Only a confirmation-required transfer is ever created
 * as 'pending'.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('stock_transfers', function (Blueprint $table) {
            $table->enum('status', ['completed', 'pending', 'rejected'])->default('completed')->after('qty');
        });
    }

    public function down(): void
    {
        Schema::table('stock_transfers', function (Blueprint $table) {
            $table->dropColumn('status');
        });
    }
};
