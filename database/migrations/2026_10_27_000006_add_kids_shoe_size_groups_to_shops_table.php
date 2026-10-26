<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * docs/ARTICLE_SERIES_PLAN.md §4 — owner-configurable size-group
 * boundaries for kids' shoe pricing (Khaled: "owner tar shop theke
 * customised kore set korte pare"). Nullable: null means "use the
 * built-in default groups" (Shop::KIDS_SHOE_SIZE_GROUPS_DEFAULT), so no
 * existing shop's behavior changes.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('shops', function (Blueprint $table) {
            // [{"label":"Small","min_size":21,"max_size":28}, ...]
            $table->json('kids_shoe_size_groups')->nullable()->after('color_code_map');
        });
    }

    public function down(): void
    {
        Schema::table('shops', function (Blueprint $table) {
            $table->dropColumn('kids_shoe_size_groups');
        });
    }
};
