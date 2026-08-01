<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * One row per physical unit (one phone = one IMEI), unlike product_batches
     * (one row can represent many units of the same batch) — a serial is
     * inherently a quantity of exactly one. Supplementary to products.stock
     * the same way batches are: stock is still the number that gates
     * checkout/reporting, serials are optional per-unit metadata for
     * after-sales warranty lookup and audit, best-effort like batches (a
     * cashier who doesn't scan/enter an IMEI at sale time just sells
     * normally, nothing blocks on this).
     */
    public function up(): void
    {
        Schema::create('product_serials', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shop_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->string('imei');
            $table->date('warranty_expiry')->nullable();
            $table->decimal('cost', 12, 2)->nullable();
            $table->enum('status', ['in_stock', 'sold'])->default('in_stock');
            $table->timestamps();

            $table->unique(['shop_id', 'imei']);
            $table->index(['shop_id', 'product_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_serials');
    }
};
