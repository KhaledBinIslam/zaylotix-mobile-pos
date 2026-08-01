<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The mirror-image of `returns` (a customer bringing something back): a
 * shop sending damaged/wrong/expired stock back TO a supplier. Stock leaves
 * (opposite direction from a customer return, where stock comes back), and
 * the value either lands as cash/bank (the supplier paid it back) or as a
 * reduction of what's still owed them (`suppliers.payable`) if the shop
 * hadn't paid for it yet.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('supplier_returns', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shop_id')->constrained()->cascadeOnDelete();
            $table->foreignId('supplier_id')->nullable()->constrained()->nullOnDelete();
            $table->string('supplier')->nullable(); // freeform, mirrors purchases.supplier for when no supplier record is picked
            $table->foreignId('product_id')->nullable()->constrained()->nullOnDelete();
            $table->integer('qty');
            $table->string('reason')->nullable();
            $table->enum('settlement_method', ['cash', 'bank', 'payable'])->default('payable');
            $table->decimal('amount', 12, 2);
            $table->date('date');
            $table->timestamps();

            $table->index('shop_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('supplier_returns');
    }
};
