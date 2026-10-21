<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 4 item #2 — "real PO/GRN" (see docs/FIX_PLAN.md's design note).
 * A wholly new, parallel system alongside the existing single-line
 * Purchase model — modeled closely on Quotation/QuotationItem's own
 * header + line-items shape and shop-scoped sequential numbering.
 * Placing a PO moves no stock or money; only PurchaseOrderController::
 * receive() does, proportional to what's actually received each time.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('shops', function (Blueprint $table) {
            $table->unsignedInteger('po_counter')->default(1000)->after('quotation_counter');
        });

        Schema::create('purchase_orders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shop_id')->constrained()->cascadeOnDelete();
            $table->foreignId('supplier_id')->constrained()->cascadeOnDelete();
            $table->string('po_number');
            $table->date('date');
            $table->date('expected_date')->nullable();
            $table->enum('status', ['ordered', 'partially_received', 'received', 'cancelled'])->default('ordered');
            $table->decimal('subtotal', 12, 2);
            $table->text('notes')->nullable();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamps();

            $table->index('shop_id');
            $table->index(['shop_id', 'status']);
        });

        Schema::create('purchase_order_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shop_id')->constrained()->cascadeOnDelete();
            $table->foreignId('purchase_order_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('product_variant_id')->nullable()->constrained()->nullOnDelete();

            $table->string('product_name'); // snapshot at order time
            $table->string('variant_label')->nullable();
            $table->decimal('qty_ordered', 10, 3);
            $table->decimal('qty_received', 10, 3)->default(0);
            $table->decimal('cost', 12, 2);

            $table->timestamps();

            $table->index('shop_id');
            $table->index('purchase_order_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('purchase_order_items');
        Schema::dropIfExists('purchase_orders');
        Schema::table('shops', function (Blueprint $table) {
            $table->dropColumn('po_counter');
        });
    }
};
