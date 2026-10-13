<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One scheduled due-date on a plan. amount_paid is credited by
 * InstallmentController::allocateToSchedule() (oldest due_date first) as
 * real Payment rows come in tagged with the plan — never written to
 * directly from the UI, so it can never drift from the sum of what was
 * actually allocated here. late_fee_charged_at is a one-shot guard: the
 * daily ApplyInstallmentLateFees command charges a fee on a newly-overdue
 * item exactly once, never again on a later run even if it's still unpaid.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('installment_schedule_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shop_id')->constrained()->cascadeOnDelete();
            $table->foreignId('installment_plan_id')->constrained()->cascadeOnDelete();
            $table->date('due_date');
            $table->decimal('amount', 12, 2);
            $table->decimal('late_fee_applied', 10, 2)->default(0);
            $table->decimal('amount_paid', 12, 2)->default(0);
            $table->string('status')->default('pending'); // pending, paid
            $table->dateTime('late_fee_charged_at')->nullable();
            $table->dateTime('paid_at')->nullable();
            $table->timestamps();

            $table->index(['shop_id', 'status', 'due_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('installment_schedule_items');
    }
};
