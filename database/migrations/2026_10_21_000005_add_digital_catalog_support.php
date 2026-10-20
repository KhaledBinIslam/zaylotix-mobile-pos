<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 3 feature 7 — "ডিজিটাল ক্যাটালগ লিঙ্ক" (see docs/FIX_PLAN.md's design
 * note). A public, no-login page listing whichever products the owner
 * opts in (show_in_catalog, default false — nothing becomes visible just
 * because the feature gets granted) with a "WhatsApp-এ অর্ডার" link, same
 * public-page pattern as the existing rate/{sale} QR flow
 * (RatingController). catalog_slug is nullable/unique and only ever set
 * once, lazily, the first time the owner opens the catalog settings page
 * — a shop that never turns the feature on never gets one.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('shops', function (Blueprint $table) {
            $table->string('catalog_slug')->nullable()->unique()->after('referral_bonus_points');
        });

        Schema::table('products', function (Blueprint $table) {
            $table->boolean('show_in_catalog')->default(false)->after('reorder_point');
        });
    }

    public function down(): void
    {
        Schema::table('shops', function (Blueprint $table) {
            $table->dropColumn('catalog_slug');
        });

        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn('show_in_catalog');
        });
    }
};
