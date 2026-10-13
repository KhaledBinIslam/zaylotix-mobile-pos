<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A structured schedule laid over a customer's existing due — it never
 * touches customers.due itself (creating a plan is pure bookkeeping, not a
 * transaction), it only describes HOW an already-owed amount is expected to
 * be paid off over time. Real collection still flows through the existing
 * Payment/PaymentController machinery (see the add_installment_plan_id_to_
 * payments migration) — a payment tagged to this plan both decrements
 * customers.due (unchanged) and gets allocated across this plan's own
 * schedule items (see InstallmentController::allocateToSchedule) so
 * "which installment is this" and "is anything overdue" can be answered
 * without a second, conflicting ledger.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('installment_plans', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shop_id')->constrained()->cascadeOnDelete();
            $table->foreignId('customer_id')->constrained()->cascadeOnDelete();
            $table->foreignId('sale_id')->nullable()->constrained()->nullOnDelete();
            $table->decimal('total_amount', 12, 2);
            $table->decimal('down_payment', 12, 2)->default(0);
            $table->unsignedInteger('installment_count');
            $table->string('frequency')->default('monthly'); // weekly, monthly
            $table->date('start_date');
            $table->string('late_fee_type')->nullable(); // flat, percent
            $table->decimal('late_fee_amount', 10, 2)->nullable();
            $table->string('status')->default('active'); // active, completed, cancelled
            $table->text('notes')->nullable();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamps();

            $table->index(['shop_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('installment_plans');
    }
};
