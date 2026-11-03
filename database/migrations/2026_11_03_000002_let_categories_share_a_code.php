<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Two categories in one shop may now carry the same code.
 *
 * Khaled, filing slippers: "Slippers er moddhe meyeder juta track korte
 * L diche evabe kaj korte hobe". To him the CODE names the sub-kind
 * (মেয়েদের = L) while the category names the product (Slippers), so the
 * same code belongs to more than one category by design. The unique
 * index was refusing the way he runs his shop.
 *
 * Barcodes stay unique regardless, which is the only thing that
 * mattered here: ArticleSerial counts by (main_shop_id, category_code),
 * not by category id. Two categories on L draw from ONE serial stream —
 * L-90010, L-90011, … — so no two articles can ever reach the same
 * barcode. Everything else that reads a code (size-group pricing, the
 * serial doctor, the next-free-serial walk) already works in terms of
 * the code itself, so both categories simply behave alike.
 *
 * A plain index replaces the unique one; the lookups it served are all
 * still "find this shop's categories on this code".
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('product_categories', function (Blueprint $table) {
            $table->dropUnique(['shop_id', 'code']);
            $table->index(['shop_id', 'code']);
        });
    }

    public function down(): void
    {
        /*
         * Deliberately not restoring the unique index: by the time this
         * is reversed the shop may legitimately hold two categories on
         * one code, and the index would refuse to build — turning a
         * rollback into a failed migration on a live database. The
         * plain index is dropped; the constraint is not put back.
         */
        Schema::table('product_categories', function (Blueprint $table) {
            $table->dropIndex(['shop_id', 'code']);
        });
    }
};
