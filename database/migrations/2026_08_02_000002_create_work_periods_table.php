<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('work_periods', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shop_id')->constrained()->cascadeOnDelete();
            $table->foreignId('opened_by')->constrained('users')->cascadeOnDelete();
            $table->dateTime('opened_at');
            $table->decimal('opening_cash', 12, 2)->default(0);
            // shop.cash_balance at the moment this shift opened/closed — the
            // shop's running cash ledger is already authoritative (see
            // CashTransaction usage app-wide), so variance is just "what was
            // physically counted" vs "what the ledger says it should be",
            // never a re-derivation of sales/refunds from scratch
            $table->decimal('cash_balance_at_open', 12, 2);
            $table->dateTime('closed_at')->nullable();
            $table->decimal('closing_cash', 12, 2)->nullable();
            $table->decimal('cash_balance_at_close', 12, 2)->nullable();
            $table->decimal('variance', 12, 2)->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('work_periods');
    }
};
