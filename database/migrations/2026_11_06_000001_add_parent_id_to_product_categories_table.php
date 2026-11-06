<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Category → sub-category, SEAMAN SHOE STORE Phase 2.
 *
 * The owner files by what a customer asks for: "Shoe" holds Safety Shoe /
 * Gumboot / DMS Boot / Formal Shoe / PT Shoe / Slipper; "Cap" holds Peak /
 * Polo / Monkey. One nullable self-reference, max TWO levels (a parent
 * must itself be top-level — enforced in CategoryController, where the
 * rule can explain itself, not by schema).
 *
 * nullOnDelete, not cascade: if a parent row is ever force-deleted outside
 * the controller's guarded path, its children become top-level categories
 * instead of silently vanishing with every product pointer they carry.
 *
 * Nullable and unused unless the sub_categories feature is granted —
 * every existing shop's categories stay exactly the flat list they were.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('product_categories', function (Blueprint $table) {
            $table->foreignId('parent_id')->nullable()->after('shop_id')
                ->constrained('product_categories')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('product_categories', function (Blueprint $table) {
            $table->dropConstrainedForeignId('parent_id');
        });
    }
};
