<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * "Online Business" module, part 3 — see docs/FIX_PLAN.md's Phase 33
 * design note. One row per settlement event (one courier, N delivered
 * orders settled together) — not per-order, matching the spec's own
 * "select delivered orders, enter received amount" batch flow.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('courier_settlements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shop_id')->constrained()->cascadeOnDelete();
            $table->foreignId('courier_id')->constrained();
            $table->decimal('expected_amount', 12, 2);
            $table->decimal('received_amount', 12, 2);
            $table->decimal('difference', 12, 2);
            $table->string('note')->nullable();
            $table->foreignId('settled_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('settled_at');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('courier_settlements');
    }
};
