<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('shops', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_type_id')->nullable()->constrained()->nullOnDelete();

            $table->string('name');
            $table->string('name_en')->nullable();
            $table->string('phone')->unique();
            $table->string('area')->nullable();
            $table->string('owner_name')->nullable();

            // per-shop app behaviour
            $table->enum('sales_mode', ['scan', 'manual', 'both'])->default('both');
            $table->string('lang', 2)->default('bn');

            // subscription / billing
            $table->string('plan')->default('trial'); // trial, monthly, yearly
            $table->enum('status', ['active', 'inactive'])->default('active');
            $table->date('subscription_start')->nullable();
            $table->date('subscription_expiry')->nullable();

            // simple accounting snapshot (cash/bank balances, capital)
            $table->decimal('cash_balance', 14, 2)->default(0);
            $table->decimal('bank_balance', 14, 2)->default(0);
            $table->decimal('capital', 14, 2)->default(0);

            // VAT/tax settings (Bangladesh turnover-based)
            $table->enum('vat_mode', ['none', 'turnover', 'full'])->default('none');
            $table->decimal('vat_rate', 5, 2)->default(15);
            $table->decimal('turnover_rate', 5, 2)->default(3);

            $table->unsignedBigInteger('invoice_counter')->default(1000);

            $table->timestamps();

            $table->index(['status', 'subscription_expiry']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('shops');
    }
};
