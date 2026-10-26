<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * docs/ARTICLE_SERIES_PLAN.md §3 — all three are nullable; null means
 * "use the built-in defaults" (App\Support\ArticleBarcode), so no
 * existing shop's behavior changes and the feature stays fully opt-in via
 * the `article_series` feature flag (granted explicitly, same convention
 * as every other opt-in feature this app has).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('shops', function (Blueprint $table) {
            // e.g. "{MFR}-{CAT}-{SERIAL}-{COSTCODE}"
            $table->string('article_barcode_template')->nullable()->after('label_settings');
            // digit (0-9, as a string key) -> letter, e.g. {"0":"X","1":"N",...}
            $table->json('cost_code_digit_map')->nullable()->after('article_barcode_template');
            // color name -> short code, e.g. {"সিলভার":"SIL"}; a color with
            // no entry here just uses its own name uppercased
            $table->json('color_code_map')->nullable()->after('cost_code_digit_map');
        });
    }

    public function down(): void
    {
        Schema::table('shops', function (Blueprint $table) {
            $table->dropColumn(['article_barcode_template', 'cost_code_digit_map', 'color_code_map']);
        });
    }
};
