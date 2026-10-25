<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * "Online Business" module, part 1 — sales channels + channel pricing
 * (see docs/FIX_PLAN.md's Phase 31 design note). Genuinely new: nothing
 * else in the codebase has a "channel" or "per-channel price override"
 * concept yet. `default_courier_id` is reserved here but gets its FK
 * constraint only once Part 3 creates `couriers` — additive, never
 * blocks this part on a table that doesn't exist yet.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sales_channels', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shop_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            // short validated string, drives the list's icon — see
            // SalesChannelController's validation, not a hardcoded rule
            $table->string('platform_type')->default('other');
            $table->string('logo_path')->nullable();
            $table->boolean('active')->default(true);
            // no FK yet — Part 3 creates `couriers` and adds the constraint
            $table->unsignedBigInteger('default_courier_id')->nullable();
            // this channel's own order-number prefix/counter — reserved now,
            // consumed starting Part 2's online order creation
            $table->string('order_prefix');
            $table->unsignedInteger('order_counter')->default(1000);
            $table->decimal('platform_fee_percent', 5, 2)->nullable();
            $table->decimal('gateway_fee_percent', 5, 2)->nullable();
            $table->timestamps();

            $table->unique(['shop_id', 'order_prefix']);
        });

        Schema::create('channel_product_prices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shop_id')->constrained()->cascadeOnDelete();
            $table->foreignId('channel_id')->constrained('sales_channels')->cascadeOnDelete();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->decimal('price', 12, 2);
            $table->timestamps();

            $table->unique(['channel_id', 'product_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('channel_product_prices');
        Schema::dropIfExists('sales_channels');
    }
};
