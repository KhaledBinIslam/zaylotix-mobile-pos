<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A price quote given to a customer before they commit — common for
 * wholesale/bulk buyers (hardware, furniture, electronics) who want a
 * written price to compare or show someone else before paying. Converting
 * one to a real sale deliberately does NOT duplicate PosController's
 * stock/money logic here — see PosController::checkout()'s `quotation_id`
 * handling, which marks a quote converted only once the real, already
 * heavily-tested checkout transaction actually succeeds.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('shops', function (Blueprint $table) {
            $table->unsignedInteger('quotation_counter')->default(1000)->after('invoice_counter');
        });

        Schema::create('quotations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shop_id')->constrained()->cascadeOnDelete();
            $table->foreignId('customer_id')->nullable()->constrained()->nullOnDelete();
            $table->string('customer_name')->nullable();
            $table->string('customer_phone')->nullable();
            $table->string('quote_no');
            $table->date('date');
            $table->date('valid_until')->nullable();
            $table->enum('status', ['open', 'converted', 'cancelled'])->default('open');
            $table->decimal('subtotal', 12, 2);
            $table->decimal('discount', 12, 2)->default(0);
            $table->decimal('total', 12, 2);
            $table->text('notes')->nullable();
            $table->foreignId('sale_id')->nullable()->constrained()->nullOnDelete();

            $table->timestamps();

            $table->index('shop_id');
            $table->index(['shop_id', 'status']);
        });

        Schema::create('quotation_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shop_id')->constrained()->cascadeOnDelete();
            $table->foreignId('quotation_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->nullable()->constrained()->nullOnDelete();

            $table->string('product_name'); // snapshot at time of quote
            $table->integer('qty');
            $table->decimal('price', 12, 2);
            $table->decimal('discount', 12, 2)->default(0);

            $table->timestamps();

            $table->index('shop_id');
            $table->index('quotation_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('quotation_items');
        Schema::dropIfExists('quotations');
        Schema::table('shops', function (Blueprint $table) {
            $table->dropColumn('quotation_counter');
        });
    }
};
