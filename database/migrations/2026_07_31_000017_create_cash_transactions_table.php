<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cash_transactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shop_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->enum('type', ['deposit', 'withdraw', 'cash_to_bank', 'bank_to_cash', 'bank_to_bank']);
            $table->decimal('amount', 14, 2);
            // Free-text account labels (e.g. "DBBL - 1234", "Owner personal")
            // for the owner's own bank-account bookkeeping — not a foreign
            // key, since the system tracks one aggregate bank_balance and
            // these are just reference notes on top of it (see
            // CashTransactionController for the reasoning on bank_to_bank).
            $table->string('from_label', 100)->nullable();
            $table->string('to_label', 100)->nullable();
            $table->string('note', 255)->nullable();
            $table->date('date');
            $table->timestamps();

            $table->index(['shop_id', 'date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cash_transactions');
    }
};
