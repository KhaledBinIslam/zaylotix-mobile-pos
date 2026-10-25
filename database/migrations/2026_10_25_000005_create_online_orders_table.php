<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * "Online Business" module, part 2 — see docs/FIX_PLAN.md's Phase 32
 * design note. A thin, additive 1:1 extension of `sales` (the Sale is
 * created together with this row, not later) carrying everything that's
 * specific to an online order: which channel, its own order number,
 * courier/tracking (reserved for Part 3, no FK yet), COD/delivery
 * amounts, campaign attribution, and the fulfillment lifecycle.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('online_orders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shop_id')->constrained()->cascadeOnDelete();
            $table->foreignId('channel_id')->constrained('sales_channels');
            $table->foreignId('sale_id')->constrained()->cascadeOnDelete();
            $table->string('order_number');
            // the channel's own order id (Daraz/Facebook export row) — used
            // only for CSV-import duplicate detection, never shown to the
            // customer
            $table->string('external_reference')->nullable();
            $table->string('status')->default('pending');
            // no FK yet — Part 3 creates `couriers` and adds the constraint
            $table->unsignedBigInteger('courier_id')->nullable();
            $table->string('tracking_id')->nullable();
            $table->decimal('delivery_charge', 12, 2)->default(0);
            $table->decimal('cod_amount', 12, 2)->default(0);
            $table->string('campaign_tag')->nullable();
            $table->timestamp('delivered_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->timestamps();

            $table->unique(['shop_id', 'order_number']);
            $table->unique(['channel_id', 'external_reference']);
            $table->index(['shop_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('online_orders');
    }
};
