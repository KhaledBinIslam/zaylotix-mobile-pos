<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('loans', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shop_id')->constrained()->cascadeOnDelete();
            $table->string('party_name');
            $table->string('phone', 20)->nullable();
            // 'given' = shop lent money out (an asset/receivable);
            // 'taken' = shop borrowed money (a liability/payable).
            $table->enum('type', ['given', 'taken']);
            $table->decimal('principal', 14, 2);
            $table->decimal('outstanding', 14, 2);
            $table->enum('method', ['cash', 'bank']);
            $table->string('note', 255)->nullable();
            $table->date('date');
            $table->timestamps();

            $table->index(['shop_id', 'type']);
        });

        Schema::create('loan_payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shop_id')->constrained()->cascadeOnDelete();
            $table->foreignId('loan_id')->constrained()->cascadeOnDelete();
            $table->decimal('amount', 14, 2);
            $table->enum('method', ['cash', 'bank']);
            $table->date('date');
            $table->timestamps();

            $table->index(['shop_id', 'loan_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('loan_payments');
        Schema::dropIfExists('loans');
    }
};
