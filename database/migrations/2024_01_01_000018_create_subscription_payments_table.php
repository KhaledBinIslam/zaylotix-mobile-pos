<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // manual subscription/payment tracking recorded by the admin
        Schema::create('subscription_payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shop_id')->constrained()->cascadeOnDelete();
            $table->foreignId('admin_id')->nullable()->constrained('admins')->nullOnDelete();

            $table->string('plan'); // trial, monthly, yearly
            $table->decimal('amount', 12, 2);
            $table->string('month'); // e.g. 2026-07
            $table->string('method')->default('cash'); // cash, bkash, bank...
            $table->date('paid_on');
            $table->date('next_due')->nullable();
            $table->string('note')->nullable();

            $table->timestamps();

            $table->index('shop_id');
            $table->index('month');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('subscription_payments');
    }
};
