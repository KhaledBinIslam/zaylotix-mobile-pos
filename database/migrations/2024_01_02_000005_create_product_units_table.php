<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Alternate sellable pack sizes for a product, on top of its base unit
     * (products.unit_id / price / stock already represent the smallest
     * sellable unit — e.g. "piece" or "tablet"). A product with, say, a
     * "box" and "strip" row here can be sold whole or broken down; stock
     * always stays counted in base units, so selling one "box" (factor 100)
     * decrements products.stock by 100 regardless of which unit was tapped.
     */
    public function up(): void
    {
        Schema::create('product_units', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shop_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->foreignId('unit_id')->constrained();
            $table->unsignedInteger('factor'); // how many base units make up one of this pack
            $table->decimal('price', 12, 2); // selling price for one of this pack
            $table->timestamps();

            $table->unique(['product_id', 'unit_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_units');
    }
};
