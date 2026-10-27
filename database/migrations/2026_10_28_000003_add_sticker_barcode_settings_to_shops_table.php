<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * docs/ARTICLE_SERIES_PLAN.md §9 — a separate template from
 * article_barcode_template (that one keeps building Product.barcode
 * exactly as before). Both nullable: null means "use the built-in
 * defaults" (App\Support\ArticleBarcode), zero behavior change for every
 * shop until it opts in.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('shops', function (Blueprint $table) {
            // e.g. "{ARTICLE}-{COLOR}-{SIZE}"
            $table->string('sticker_barcode_template')->nullable()->after('kids_shoe_size_groups');
            // off by default -- {SIZE} resolves to empty (then cleaned up) until turned on
            $table->boolean('barcode_include_size')->default(false)->after('sticker_barcode_template');
        });
    }

    public function down(): void
    {
        Schema::table('shops', function (Blueprint $table) {
            $table->dropColumn(['sticker_barcode_template', 'barcode_include_size']);
        });
    }
};
