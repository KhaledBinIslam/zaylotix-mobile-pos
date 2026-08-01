<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Actual money tendered at checkout — a sale can now carry more than
        // one of these (e.g. part cash + part bkash), with whatever isn't
        // covered here becoming the attached customer's due. sales.payment_mode
        // stays for backward-compatible single-tender display and gains
        // 'split' for when more than one row exists here.
        Schema::create('sale_payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shop_id')->constrained()->cascadeOnDelete();
            $table->foreignId('sale_id')->constrained()->cascadeOnDelete();
            $table->enum('method', ['cash', 'bkash', 'nagad']);
            $table->decimal('amount', 12, 2);
            $table->timestamps();

            $table->index(['shop_id', 'sale_id']);
        });

        Schema::table('sales', function (Blueprint $table) {
            $table->enum('payment_mode', ['cash', 'bkash', 'nagad', 'credit', 'split'])->default('cash')->change();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sale_payments');

        Schema::table('sales', function (Blueprint $table) {
            $table->enum('payment_mode', ['cash', 'bkash', 'nagad', 'credit'])->default('cash')->change();
        });
    }
};
