<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('salary_payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shop_id')->constrained()->cascadeOnDelete();
            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();
            $table->string('month', 7); // 'YYYY-MM' — one payroll run per employee per month
            $table->decimal('basic_salary', 12, 2);
            $table->integer('present_days')->nullable();
            $table->integer('absent_days')->nullable();
            $table->decimal('attendance_deduction', 12, 2)->default(0);
            $table->decimal('bonus', 12, 2)->default(0);
            $table->decimal('advance_deduction', 12, 2)->default(0);
            $table->decimal('net_paid', 12, 2);
            $table->enum('method', ['cash', 'bank']);
            $table->date('paid_date');
            $table->timestamps();

            // one payroll payment per employee per month — paying the same
            // month twice would silently double an employee's salary
            $table->unique(['employee_id', 'month']);
            $table->index(['shop_id', 'month']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('salary_payments');
    }
};
