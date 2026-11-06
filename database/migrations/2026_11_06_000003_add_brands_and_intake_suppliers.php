<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * SEAMAN Phase 4 — brands, and the supplier on every intake.
 *
 * brand_id sits on the product AND on the variant because the owner's
 * catalogue mixes both shapes: one helmet product in four brands (brand
 * per variant), one Lotus shoe wholly one brand (brand on the product).
 * A variant's brand overrides its product's where both are set; either
 * missing shows "Nil", never blank.
 *
 * stock_intakes.supplier_id: the ledger already records WHAT came in at
 * WHICH cost — now also from WHOM, because the same Power X shoe really
 * does arrive from Safety Gallery at 1600 and Jubilee at 1750 and month-
 * end questions are per supplier. nullOnDelete everywhere: deleting a
 * brand or supplier must never take history or products down with it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('brands', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shop_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->timestamps();

            $table->unique(['shop_id', 'name']);
        });

        Schema::table('products', function (Blueprint $table) {
            $table->foreignId('brand_id')->nullable()->after('supplier_id')
                ->constrained('brands')->nullOnDelete();
        });

        Schema::table('product_variants', function (Blueprint $table) {
            $table->foreignId('brand_id')->nullable()->after('product_id')
                ->constrained('brands')->nullOnDelete();
        });

        Schema::table('stock_intakes', function (Blueprint $table) {
            $table->foreignId('supplier_id')->nullable()->after('user_id')
                ->constrained('suppliers')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('stock_intakes', function (Blueprint $table) {
            $table->dropConstrainedForeignId('supplier_id');
        });
        Schema::table('product_variants', function (Blueprint $table) {
            $table->dropConstrainedForeignId('brand_id');
        });
        Schema::table('products', function (Blueprint $table) {
            $table->dropConstrainedForeignId('brand_id');
        });
        Schema::dropIfExists('brands');
    }
};
