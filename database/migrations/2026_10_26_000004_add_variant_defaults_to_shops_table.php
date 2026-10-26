<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Saved, editable comma-separated size/color presets so Stock/Index.vue's
 * variant grid (gridColorsInput/gridSizesInput) can pre-fill instead of the
 * owner retyping the same list every time — see docs/BABY_PARK_PLAN.md.
 * Both nullable, default null — zero behavior change for every existing
 * shop (the grid still opens empty exactly as before until a shop sets
 * these).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('shops', function (Blueprint $table) {
            $table->string('default_variant_sizes')->nullable()->after('default_credit_limit');
            $table->string('default_variant_colors')->nullable()->after('default_variant_sizes');
        });
    }

    public function down(): void
    {
        Schema::table('shops', function (Blueprint $table) {
            $table->dropColumn(['default_variant_sizes', 'default_variant_colors']);
        });
    }
};
