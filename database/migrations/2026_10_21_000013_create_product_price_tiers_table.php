<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 4 "full Wholesale system", part A — tiered quantity pricing +
 * MOQ (see docs/FIX_PLAN.md's design note). Additive on top of the
 * existing flat products.wholesale_price — a product with no tier rows
 * here falls straight back to that flat price (or retail), so a shop
 * using only the simple flat-price wholesale mechanism sees zero change.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            // hard gate at wholesale checkout, not a soft fallback — see
            // the design note; null means no MOQ enforced, same as today
            $table->unsignedInteger('wholesale_moq')->nullable()->after('wholesale_price');
        });

        Schema::create('product_price_tiers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shop_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('min_qty');
            $table->decimal('price', 12, 2);
            $table->timestamps();

            $table->index(['shop_id', 'product_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_price_tiers');
        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn('wholesale_moq');
        });
    }
};
