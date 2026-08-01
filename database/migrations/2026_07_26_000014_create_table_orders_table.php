<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A restaurant's "open tab" for one seated table — genuinely different
     * from the rest of the app's instant checkout (PosController): items
     * get added over time as the party orders more, stock is deducted as
     * each item is actually ordered (not deferred to billing — once it
     * leaves the kitchen it's gone whether or not the table has paid yet),
     * and only settling the bill (TableOrderController::bill) creates the
     * real, standard Sale/SaleItem rows — from that point on it behaves
     * exactly like any other sale (voidable, reportable, etc.) with no
     * special-casing needed anywhere else in the app.
     */
    public function up(): void
    {
        Schema::create('table_orders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shop_id')->constrained()->cascadeOnDelete();
            $table->foreignId('restaurant_table_id')->constrained()->cascadeOnDelete();
            // 'merged' (added later, see add_restaurant_split_bill_and_extras)
            // means this order's items were folded into another table's
            // order rather than sold or reversed — kept here, not just
            // widened on the real DB via a later raw ALTER, so a fresh
            // install (and the sqlite test DB, which has no ALTER-COLUMN
            // support for CHECK-constrained enums) gets the full set immediately
            $table->enum('status', ['open', 'billed', 'cancelled', 'merged'])->default('open');
            $table->string('customer_name')->nullable();
            $table->foreignId('sale_id')->nullable()->constrained()->nullOnDelete(); // set once billed
            $table->timestamp('opened_at');
            $table->timestamps();

            $table->index(['shop_id', 'restaurant_table_id']);
            $table->index(['shop_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('table_orders');
    }
};
