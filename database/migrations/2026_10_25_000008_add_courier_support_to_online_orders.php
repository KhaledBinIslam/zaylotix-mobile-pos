<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * "Online Business" module, part 3 — see docs/FIX_PLAN.md's Phase 33
 * design note. Adds the real FK constraints for the courier_id/
 * default_courier_id columns Parts 1-2 reserved (couriers didn't exist
 * yet then), plus the fields needed to estimate and later settle a
 * parcel's own delivery cost.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('online_orders', function (Blueprint $table) {
            $table->foreign('courier_id')->references('id')->on('couriers')->nullOnDelete();
            $table->decimal('courier_charge', 12, 2)->nullable()->after('courier_id');
            $table->decimal('parcel_weight', 8, 3)->nullable()->after('courier_charge');
            $table->boolean('is_outside_dhaka')->nullable()->after('parcel_weight');
            $table->timestamp('cod_settled_at')->nullable()->after('cancelled_at');
            $table->foreignId('courier_settlement_id')->nullable()->after('cod_settled_at')->constrained()->nullOnDelete();
        });

        Schema::table('sales_channels', function (Blueprint $table) {
            $table->foreign('default_courier_id')->references('id')->on('couriers')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('sales_channels', function (Blueprint $table) {
            $table->dropForeign(['default_courier_id']);
        });

        Schema::table('online_orders', function (Blueprint $table) {
            $table->dropForeign(['courier_settlement_id']);
            $table->dropForeign(['courier_id']);
            $table->dropColumn(['courier_charge', 'parcel_weight', 'is_outside_dhaka', 'cod_settled_at', 'courier_settlement_id']);
        });
    }
};
