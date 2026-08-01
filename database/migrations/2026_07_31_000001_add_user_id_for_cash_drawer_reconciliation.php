<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Multi-cashier support (the one-cashier-per-shop cap is being lifted in
 * this same batch of work) needs a way to tell each cashier's cash apart at
 * shift-end. `sales.user_id` already exists; due-collections and refunds
 * are the other two everyday cash movements a cashier personally handles at
 * the counter, so they get the same tracking. Purchases/expenses are left
 * out on purpose — those are normally an owner/back-office action, not
 * something rung up at the register, so attributing them per-cashier
 * wouldn't reflect what's actually in a specific person's drawer.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->foreignId('user_id')->nullable()->after('customer_id')->constrained()->nullOnDelete();
        });

        Schema::table('returns', function (Blueprint $table) {
            $table->foreignId('user_id')->nullable()->after('product_id')->constrained()->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('returns', function (Blueprint $table) {
            $table->dropConstrainedForeignId('user_id');
        });
        Schema::table('payments', function (Blueprint $table) {
            $table->dropConstrainedForeignId('user_id');
        });
    }
};
