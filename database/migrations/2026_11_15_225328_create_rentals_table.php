<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('rentals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shop_id')->constrained()->cascadeOnDelete();
            $table->foreignId('customer_id')->constrained()->cascadeOnDelete();
            $table->string('invoice_no');
            $table->date('rental_date');
            $table->date('expected_return_date');
            $table->date('actual_return_date')->nullable();
            // active, partially_returned, returned, cancelled
            $table->string('status')->default('active');
            $table->decimal('total_rent_amount', 12, 2);
            $table->decimal('security_deposit', 10, 2)->nullable();
            $table->text('notes')->nullable();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamp('voided_at')->nullable();
            // Idempotency key, same pattern as sales.client_uuid — a
            // resubmitted "নতুন রেন্টাল" tap (independent of whether an
            // advance was given) must never create a second rental.
            $table->uuid('client_uuid')->nullable();
            $table->timestamps();

            $table->unique(['shop_id', 'invoice_no']);
            $table->unique(['shop_id', 'client_uuid']);
            $table->index(['shop_id', 'status']);
            $table->index(['shop_id', 'expected_return_date']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('rentals');
    }
};
