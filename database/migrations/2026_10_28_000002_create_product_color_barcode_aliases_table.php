<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * docs/ARTICLE_SERIES_PLAN.md §9 — append-only history of every value
 * product_color_barcodes.barcode has ever held for a (product, color), so
 * a sticker printed before a color-code rename / template edit / a
 * products:regenerate-barcodes run stays scannable forever. Never updated
 * in place, only inserted into and read from.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('product_color_barcode_aliases', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shop_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->string('color');
            $table->string('barcode');
            $table->timestamp('created_at')->nullable();

            $table->unique(['shop_id', 'barcode']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_color_barcode_aliases');
    }
};
