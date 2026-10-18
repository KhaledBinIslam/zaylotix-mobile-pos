<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 4.6 (see docs/FIX_PLAN.md) — a supplier payable settlement
 * (SupplierPaymentController::store()) previously only ever decremented
 * Supplier.payable and the shop's cash/bank balance, logging just a plain
 * Activity text line — there was no structured, queryable record of what
 * was paid when, so no per-supplier ledger page could ever be built from
 * it. Mirrors `payments` (the customer-due equivalent) exactly, including
 * the before/after snapshot.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('supplier_payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shop_id')->constrained()->cascadeOnDelete();
            $table->foreignId('supplier_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->decimal('amount', 12, 2);
            $table->decimal('payable_before', 12, 2)->nullable();
            $table->decimal('payable_after', 12, 2)->nullable();
            $table->string('method')->default('cash');
            $table->date('date');
            $table->timestamps();

            $table->index(['shop_id', 'supplier_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('supplier_payments');
    }
};
