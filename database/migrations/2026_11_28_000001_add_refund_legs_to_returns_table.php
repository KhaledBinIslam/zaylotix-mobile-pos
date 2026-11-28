<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * QA D2 / D11 (owner decision 2026-11-28): a refund goes back the way the customer paid, and the books must be able to
 * say HOW MUCH of it came off the cash drawer, off the bank/bKash balance, and off the customer's due.
 *
 * Until now `returns.applied_to_due` was only a yes/no flag, so the split could not be rebuilt from the ledger and a void
 * after a return had to guess it. These three columns record the split per return row (they add up to `refund`).
 *
 * Purely additive and nullable: nothing is back-filled and no existing row is touched. A row with NULL legs is a row
 * written before this change — it was refunded from cash, and SaleReversal keeps treating it exactly that way.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('returns', function (Blueprint $table) {
            $table->decimal('refund_cash', 12, 2)->nullable()->after('applied_to_due');
            $table->decimal('refund_bank', 12, 2)->nullable()->after('refund_cash');
            $table->decimal('refund_due', 12, 2)->nullable()->after('refund_bank');
        });
    }

    public function down(): void
    {
        Schema::table('returns', function (Blueprint $table) {
            $table->dropColumn(['refund_cash', 'refund_bank', 'refund_due']);
        });
    }
};
