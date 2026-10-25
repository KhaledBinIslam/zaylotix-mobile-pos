<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * "Online Business" module, part 5 — see docs/FIX_PLAN.md's Phase 35
 * design note. One row per channel an ad expense is allocated to.
 * An `Expense` with zero rows here is a plain expense, unaffected;
 * `Expense::has('channelSplits')` is how the ads/campaign pages tell
 * ad spend apart from everything else in the expense table.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('expense_channel_splits', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shop_id')->constrained()->cascadeOnDelete();
            $table->foreignId('expense_id')->constrained()->cascadeOnDelete();
            $table->foreignId('channel_id')->constrained('sales_channels')->cascadeOnDelete();
            $table->decimal('amount', 12, 2);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('expense_channel_splits');
    }
};
