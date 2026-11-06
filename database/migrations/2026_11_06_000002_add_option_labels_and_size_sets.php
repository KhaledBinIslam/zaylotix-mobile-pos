<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * SEAMAN Phase 3 — variants beyond colour/size, with the least change
 * that can possibly work.
 *
 * Option LABELS, not new value columns: a variant's two values keep
 * living in product_variants.color and .size exactly as they always
 * have (stock, price, cost, barcode, POS grouping, labels — nothing
 * moves). The product just learns what its two options are CALLED:
 * option1_label renames the colour axis ("Rank"), option2_label the
 * size axis ("Type"). Null = রং/সাইজ as today, which is every existing
 * product in every existing shop.
 *
 * size_sets: named, ordered lists of sizes the owner reuses ("Letter
 * S–4XL", "Shoe 39–44", "Peak cap 20.5–23"). A category may point at
 * one as its default; a sub-category's own pick overrides its
 * parent's; the grid prefill is only ever a SUGGESTION the owner edits
 * freely, so no product-level column is needed.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->string('option1_label', 50)->nullable()->after('color');
            $table->string('option2_label', 50)->nullable()->after('option1_label');
        });

        Schema::create('size_sets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shop_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->json('sizes'); // ordered list, e.g. ["20.5","21","21.5"]
            $table->timestamps();

            $table->index('shop_id');
        });

        Schema::table('product_categories', function (Blueprint $table) {
            $table->foreignId('size_set_id')->nullable()->after('serial_start')
                ->constrained('size_sets')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('product_categories', function (Blueprint $table) {
            $table->dropConstrainedForeignId('size_set_id');
        });
        Schema::dropIfExists('size_sets');
        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn(['option1_label', 'option2_label']);
        });
    }
};
