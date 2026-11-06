<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * SEAMAN — "one product, many sources": the same item bought from
 * different suppliers/brands at different buying prices, sold at
 * different sell prices, each with its own stock. A product_sources row
 * is one such "lot/line" — belongs to a product_variant when the product
 * has variants, or straight to the product when it doesn't (never both
 * null). Gated by the `source_lines` feature; a shop without it never
 * creates or reads a single row here.
 *
 * products.stock / product_variants.stock / .cost / .price are UNTOUCHED
 * in meaning — they stay the running summary (sum of stock, last/blended
 * cost, default sell price) that every existing report, POS path and
 * reorder alert already reads. A line write always updates its mirror in
 * the same transaction, so nothing outside this feature has to change at
 * all — see products:seed-source-lines for how an existing product's
 * one implicit "line" is backfilled when a shop turns this on.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('product_sources', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shop_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            // null = this line belongs to the plain (variantless) product itself
            $table->foreignId('product_variant_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('supplier_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('brand_id')->nullable()->constrained()->nullOnDelete();
            $table->decimal('cost', 12, 2)->default(0);
            $table->decimal('price', 12, 2)->default(0);
            $table->decimal('stock', 12, 3)->default(0);
            $table->string('barcode')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['shop_id', 'product_id']);
            $table->index(['shop_id', 'product_variant_id']);
            // same convention as product_variants.barcode: unique per shop,
            // a soft-deleted row's barcode is released (see ProductSource::booted())
            $table->unique(['shop_id', 'barcode']);
        });

        // Which exact lot a sale actually depleted — so profit uses that
        // lot's real cost (not a blended average) and a return/void can
        // restock the SAME line it came from. Nullable: every sale on a
        // shop without this feature, and every sale that happened before
        // it existed, simply has none.
        Schema::table('sale_items', function (Blueprint $table) {
            $table->foreignId('product_source_id')->nullable()->after('product_variant_id')->constrained()->nullOnDelete();
        });

        // Stock-in ledger: which lot this intake actually fed. supplier_id
        // already existed (Phase 4); this pins it to the exact line too.
        Schema::table('stock_intakes', function (Blueprint $table) {
            $table->foreignId('product_source_id')->nullable()->after('supplier_id')->constrained()->nullOnDelete();
        });

        // Damages: optional — a shop that never names a line keeps working
        // exactly as before (deducts the mirror only).
        Schema::table('damages', function (Blueprint $table) {
            $table->foreignId('product_source_id')->nullable()->after('product_variant_id')->constrained()->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('damages', function (Blueprint $table) {
            $table->dropConstrainedForeignId('product_source_id');
        });
        Schema::table('stock_intakes', function (Blueprint $table) {
            $table->dropConstrainedForeignId('product_source_id');
        });
        Schema::table('sale_items', function (Blueprint $table) {
            $table->dropConstrainedForeignId('product_source_id');
        });
        Schema::dropIfExists('product_sources');
    }
};
