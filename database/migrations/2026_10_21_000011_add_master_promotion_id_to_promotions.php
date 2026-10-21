<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 4 item #3 — "ব্রাঞ্চ-জুড়ে প্রোমোশন পুলিং" (see docs/FIX_PLAN.md's
 * design note). Same shape as products.master_product_id — nullable,
 * self-referential, unused by every shop not using the opt-in
 * shared_promotions feature.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('promotions', function (Blueprint $table) {
            $table->foreignId('master_promotion_id')->nullable()->after('shop_id')->constrained('promotions')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('promotions', function (Blueprint $table) {
            $table->dropConstrainedForeignId('master_promotion_id');
        });
    }
};
