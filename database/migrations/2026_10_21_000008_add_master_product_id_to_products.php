<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 4 item #1 — "শেয়ার্ড ক্যাটালগ" (see docs/FIX_PLAN.md's design note).
 * Makes CatalogSync's existing main-shop-to-branch product matching
 * explicit/durable instead of relying purely on barcode/name (which breaks
 * the moment either side is renamed with no barcode set) — set once, by
 * CatalogSync itself, the first time a given pair of rows is ever matched
 * (by the existing barcode/name fallback), then used directly on every
 * sync after that. Nullable, self-referential, never required — a plain
 * single-shop business (the overwhelming majority) never has any row with
 * this set, and nothing here changes what CatalogSync already did for an
 * existing multi-branch business; this purely makes its own matching more
 * reliable going forward.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->foreignId('master_product_id')->nullable()->after('shop_id')->constrained('products')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropConstrainedForeignId('master_product_id');
        });
    }
};
