<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Widens both payment-method enums to add 'gateway' — a real bKash/Nagad/SSLCommerz online payment, confirmed by GatewayWebhookController, distinct from the pre-existing 'bkash'/'nagad' values which are just a cashier's manual note that cash physically changed hands via that channel. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sale_payments', function (Blueprint $table) {
            $table->enum('method', ['cash', 'bkash', 'nagad', 'gateway'])->change();
        });

        Schema::table('sales', function (Blueprint $table) {
            $table->enum('payment_mode', ['cash', 'bkash', 'nagad', 'credit', 'split', 'gateway'])->default('cash')->change();
        });
    }

    public function down(): void
    {
        Schema::table('sales', function (Blueprint $table) {
            $table->enum('payment_mode', ['cash', 'bkash', 'nagad', 'credit', 'split'])->default('cash')->change();
        });

        Schema::table('sale_payments', function (Blueprint $table) {
            $table->enum('method', ['cash', 'bkash', 'nagad'])->change();
        });
    }
};
