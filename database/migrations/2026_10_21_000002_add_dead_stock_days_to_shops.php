<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 3 feature 3 — "ডেড স্টক ক্লিয়ারেন্স". Same shape as the existing
 * shops.return_window_days — a plain, shop-owned, shop-configurable
 * threshold, not a new settings table. 60 is a reasonable, unsurprising
 * default for every existing shop (nobody's current behavior changes
 * until they actually open the new Dead Stock page).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('shops', function (Blueprint $table) {
            $table->unsignedInteger('dead_stock_days')->default(60)->after('mobile_nav_items');
        });
    }

    public function down(): void
    {
        Schema::table('shops', function (Blueprint $table) {
            $table->dropColumn('dead_stock_days');
        });
    }
};
