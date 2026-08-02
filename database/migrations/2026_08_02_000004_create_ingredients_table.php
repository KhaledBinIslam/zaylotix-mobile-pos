<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ingredients', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shop_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            // freeform label (kg/litre/pcs/gram/...) rather than a foreign key
            // to Unit — ingredients are a much smaller, simpler list than
            // products, and don't need pack-size conversion the way products do
            $table->string('unit');
            $table->decimal('stock', 12, 3)->default(0);
            $table->decimal('cost', 12, 2)->default(0);
            $table->decimal('reorder_point', 12, 3)->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ingredients');
    }
};
