<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A true variant, unlike product_units (a pack-size conversion of one
     * shared stock pool) — a "Red, Medium" shirt and a "Blue, Large" shirt
     * are physically different items with their own independent stock, not
     * different quantities of the same thing. Each variant keeps its own
     * stock count, but products.stock is maintained as the live sum of all
     * its variants' stock (see ProductVariantController/PosController) so
     * every existing report/alert/valuation query that already reads
     * products.stock keeps working unchanged, whether or not a product has
     * variants.
     */
    public function up(): void
    {
        Schema::create('product_variants', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shop_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->string('size')->nullable();
            $table->string('color')->nullable();
            $table->string('barcode')->nullable();
            $table->unsignedInteger('stock')->default(0);
            $table->decimal('price', 12, 2)->nullable(); // null = inherit the parent product's price
            $table->decimal('cost', 12, 2)->nullable();  // null = inherit the parent product's cost
            $table->timestamps();
            $table->softDeletes();

            $table->index(['shop_id', 'product_id']);
            $table->index('barcode');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_variants');
    }
};
