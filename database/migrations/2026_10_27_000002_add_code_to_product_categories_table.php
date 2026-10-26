<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * docs/ARTICLE_SERIES_PLAN.md §2 — feeds the article barcode's {CAT}
 * segment (e.g. "L" for Ladies). Nullable, shop-scoped unique — a
 * category that never needs an article barcode never sets one.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('product_categories', function (Blueprint $table) {
            $table->string('code', 10)->nullable()->after('name_en');
            $table->unique(['shop_id', 'code']);
        });
    }

    public function down(): void
    {
        Schema::table('product_categories', function (Blueprint $table) {
            $table->dropUnique(['shop_id', 'code']);
            $table->dropColumn('code');
        });
    }
};
