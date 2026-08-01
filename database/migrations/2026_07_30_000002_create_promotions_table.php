<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One table covers both promotion styles a small shop actually runs:
 *  - 'bogo': buy N of a product, get M of a product (same one, or a
 *    different one — "buy 1 burger get 1 drink free" is the same shape as
 *    "buy 2 get 1 free") free or discounted. Auto-applies at checkout,
 *    no code needed.
 *  - 'coupon': a code the cashier types in, a flat or percent discount off
 *    the whole cart, optionally gated by a minimum purchase, a date
 *    window, and a total redemption cap.
 * Columns for both styles live on one row (nullable as needed) rather than
 * two tables — the fields don't overlap and a shop's promo list is small
 * enough that a wide row is simpler than a join.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('promotions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shop_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->enum('type', ['bogo', 'coupon']);
            $table->boolean('active')->default(true);

            // coupon
            $table->string('code')->nullable();
            $table->enum('discount_type', ['percent', 'fixed'])->nullable();
            $table->decimal('discount_value', 10, 2)->nullable();
            $table->decimal('min_purchase', 10, 2)->nullable();
            $table->unsignedInteger('usage_limit')->nullable();

            // bogo
            $table->foreignId('buy_product_id')->nullable()->constrained('products')->nullOnDelete();
            $table->unsignedInteger('buy_qty')->nullable();
            $table->foreignId('get_product_id')->nullable()->constrained('products')->nullOnDelete();
            $table->unsignedInteger('get_qty')->nullable();
            $table->decimal('get_discount_percent', 5, 2)->nullable();

            // shared
            $table->unsignedInteger('used_count')->default(0);
            $table->date('starts_at')->nullable();
            $table->date('expires_at')->nullable();

            $table->timestamps();

            $table->index('shop_id');
            $table->index(['shop_id', 'code']);
        });

        Schema::table('sales', function (Blueprint $table) {
            $table->string('coupon_code')->nullable()->after('discount');
        });
    }

    public function down(): void
    {
        Schema::table('sales', function (Blueprint $table) {
            $table->dropColumn('coupon_code');
        });
        Schema::dropIfExists('promotions');
    }
};
