<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A line on an open service ticket — either a real inventory part
 * (product_id set, stock taken the moment it's added, same timing as
 * TableOrderItem) or a free-typed line with no stock implication: labor,
 * or a part not carried in inventory. is_labor is purely a reporting
 * label — nothing except the bill-time SaleItem cares about it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('service_ticket_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shop_id')->constrained()->cascadeOnDelete();
            $table->foreignId('service_ticket_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->nullable()->constrained()->nullOnDelete();
            $table->boolean('is_labor')->default(false);
            $table->string('label');
            $table->decimal('qty', 10, 3)->default(1);
            $table->decimal('cost', 10, 2)->nullable();
            $table->decimal('price', 10, 2);
            $table->foreignId('sale_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('service_ticket_items');
    }
};
