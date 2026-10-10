<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Extends the existing no-receipt "lifetime cap" return flow (ReturnController::store,
 * unchanged) with the columns an invoice-linked return/exchange needs. Every new
 * column is nullable so every existing row (and the old flow, which never sets
 * them) keeps working exactly as before.
 *
 * `cost` is deliberately nullable, not backfilled here — old rows keep it NULL
 * forever unless Khaled explicitly asks for a backfill later (see Reports::
 * rangeStats, which branches on NULL vs set).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('returns', function (Blueprint $table) {
            $table->foreignId('sale_id')->nullable()->after('shop_id')->constrained()->nullOnDelete();
            $table->foreignId('sale_item_id')->nullable()->after('sale_id')->constrained()->nullOnDelete();
            $table->foreignId('product_variant_id')->nullable()->after('product_id')->constrained()->nullOnDelete();
            $table->foreignId('customer_id')->nullable()->after('product_variant_id')->constrained()->nullOnDelete();
            $table->foreignId('exchange_sale_id')->nullable()->after('sale_item_id')->constrained('sales')->nullOnDelete();
            $table->string('type', 20)->default('return')->after('exchange_sale_id'); // 'return' | 'exchange'
            $table->string('condition', 20)->default('resalable')->after('type'); // 'resalable' | 'damaged'
            $table->decimal('cost', 12, 2)->nullable()->after('refund');
            $table->boolean('applied_to_due')->default(false)->after('cost');
            $table->integer('loyalty_points_deducted')->nullable()->after('applied_to_due');
        });
    }

    public function down(): void
    {
        Schema::table('returns', function (Blueprint $table) {
            $table->dropForeign(['sale_id']);
            $table->dropForeign(['sale_item_id']);
            $table->dropForeign(['product_variant_id']);
            $table->dropForeign(['customer_id']);
            $table->dropForeign(['exchange_sale_id']);
            $table->dropColumn(['sale_id', 'sale_item_id', 'product_variant_id', 'customer_id', 'exchange_sale_id', 'type', 'condition', 'cost', 'applied_to_due', 'loyalty_points_deducted']);
        });
    }
};
