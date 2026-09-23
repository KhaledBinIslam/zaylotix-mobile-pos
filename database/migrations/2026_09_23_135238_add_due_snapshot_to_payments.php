<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A customer's due collections were only ever visible as a single running
 * `customers.due` number — collecting ৳750 against a ৳1500 due left no
 * record anywhere of what the due actually was before/after that specific
 * payment, so a shop owner had no way to look back and see "who paid what,
 * when, leaving how much still owed" (Khaled's explicit request: a real
 * payment ledger per customer). `payments` already recorded amount/method/
 * date per collection (PaymentController already writes one row per
 * collect-due action) - this just adds the before/after snapshot each row
 * was always missing, filled in going forward by PaymentController.
 * Nullable, not backfilled: an already-existing payment row's true
 * before/after can't be reconstructed after the fact without re-deriving
 * it from every other payment on that customer in date order, which risks
 * being subtly wrong for edited/out-of-order historical data - the ledger
 * UI treats a null pair as "not available" rather than guessing.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->decimal('due_before', 12, 2)->nullable()->after('amount');
            $table->decimal('due_after', 12, 2)->nullable()->after('due_before');
        });
    }

    public function down(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->dropColumn(['due_before', 'due_after']);
        });
    }
};
