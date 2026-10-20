<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 3 feature 5 — "অ্যাডভান্স বুকিং/লে-অ্যাওয়ে" (see docs/FIX_PLAN.md's
 * design note). Deliberately NOT a new table/model: an advance booking is
 * mechanically identical to the installment flow that already exists —
 * a sale with a down payment, the rest scheduled and tracked via
 * InstallmentPlan — so this just tags which plans are a "hold this item
 * for pickup" booking rather than ordinary financing, plus whether it's
 * been physically picked up yet (the one genuinely new concept: a
 * financed item is usually taken home immediately, a booked/reserved one
 * often isn't). `booking` defaults false — every existing plan keeps
 * reading as a plain installment, zero behavior change.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('installment_plans', function (Blueprint $table) {
            $table->boolean('booking')->default(false)->after('sale_id');
            $table->timestamp('picked_up_at')->nullable()->after('status');
        });
    }

    public function down(): void
    {
        Schema::table('installment_plans', function (Blueprint $table) {
            $table->dropColumn(['booking', 'picked_up_at']);
        });
    }
};
