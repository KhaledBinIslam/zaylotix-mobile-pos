<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            // Same shape as the existing nullable sale_id/installment_plan_id
            // columns — a rental's advance/due collection goes through this
            // exact ledger (due_before/due_after snapshot, client_uuid
            // idempotency) rather than a second, parallel one.
            $table->foreignId('rental_id')->nullable()->after('installment_plan_id')->constrained()->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->dropConstrainedForeignId('rental_id');
        });
    }
};
