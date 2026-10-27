<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * docs/ARTICLE_SERIES_PLAN.md §9 — one row per (product, color), shared by
 * every size variant of that color (never per-variant — see the plan's own
 * reasoning for why a column on product_variants would collide across a
 * color group). The scannable "tier 2" barcode for a printed sticker when
 * shops.barcode_include_size is off.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('product_color_barcodes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shop_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->string('color');
            $table->string('barcode');
            $table->timestamps();

            $table->unique(['shop_id', 'barcode']);
            $table->unique(['product_id', 'color']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_color_barcodes');
    }
};
