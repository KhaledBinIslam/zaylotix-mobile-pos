<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * SkyBuy-style color picker (per Khaled's reference) — one optional photo
 * per color. Lives on ProductVariant (not a new table) since every other
 * per-variant override (barcode/price/cost) already lives here; a color's
 * "representative" photo is just whichever of its same-color variant rows
 * has one set (see ArticleController/ProductVariantController). Nullable
 * and additive — zero behavior change for every existing variant.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('product_variants', function (Blueprint $table) {
            $table->string('photo_path')->nullable()->after('cost');
        });
    }

    public function down(): void
    {
        Schema::table('product_variants', function (Blueprint $table) {
            $table->dropColumn('photo_path');
        });
    }
};
