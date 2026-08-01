<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ReturnController::store sums past sale_items for a product on every
 * return submission (SUM(qty*unit_factor) WHERE shop_id AND product_id) —
 * sale_items is one of the fastest-growing tables (tens of thousands of
 * rows/year for an active shop) and had no index covering that query.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sale_items', function (Blueprint $table) {
            $table->index(['shop_id', 'product_id']);
        });
    }

    public function down(): void
    {
        Schema::table('sale_items', function (Blueprint $table) {
            $table->dropIndex(['shop_id', 'product_id']);
        });
    }
};
