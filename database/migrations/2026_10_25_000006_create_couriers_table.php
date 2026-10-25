<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * "Online Business" module, part 3 — see docs/FIX_PLAN.md's Phase 33
 * design note. Owner-defined, no hardcoded courier list. charge_config's
 * shape depends on charge_type — see Courier model / CourierCharge.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('couriers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shop_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('charge_type')->default('flat'); // flat | weight | zone
            $table->json('charge_config')->nullable();
            $table->decimal('cod_fee_percent', 5, 2)->nullable();
            $table->decimal('return_charge', 12, 2)->nullable();
            $table->boolean('active')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('couriers');
    }
};
