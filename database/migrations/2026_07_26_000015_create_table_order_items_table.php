<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('table_order_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shop_id')->constrained()->cascadeOnDelete();
            $table->foreignId('table_order_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->nullable()->constrained()->nullOnDelete();
            $table->string('product_name'); // snapshot, same reasoning as sale_items
            $table->unsignedInteger('qty');
            $table->decimal('price', 12, 2); // snapshot at order time
            $table->decimal('cost', 12, 2);  // snapshot, for profit calc once billed
            $table->timestamp('kot_printed_at')->nullable();
            $table->timestamps();

            $table->index(['shop_id', 'table_order_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('table_order_items');
    }
};
