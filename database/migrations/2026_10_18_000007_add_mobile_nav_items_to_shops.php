<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 8 (see docs/FIX_PLAN.md) — the mobile bottom nav's two flexible
 * slots (between the always-fixed Home/Sell/More) were hardcoded to
 * Stock + Due for every shop regardless of business type. Nullable, with
 * null resolved to exactly that same default (see Shop::mobileNavItems())
 * — every existing shop sees zero change until they actually customize it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('shops', function (Blueprint $table) {
            $table->json('mobile_nav_items')->nullable()->after('label_settings');
        });
    }

    public function down(): void
    {
        Schema::table('shops', function (Blueprint $table) {
            $table->dropColumn('mobile_nav_items');
        });
    }
};
