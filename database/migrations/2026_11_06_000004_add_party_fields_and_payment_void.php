<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * SEAMAN Phase 5 — corporate parties and dues.
 *
 * A "party" is NOT a second customer system: it is an existing customers
 * row with party_type set ('company' or 'person'), so every existing due,
 * payment, credit-limit and POS flow keeps working on it unchanged. The
 * extra columns only describe the party; all money math stays where it is.
 *
 * payments gains a void trail: voiding never deletes or edits the original
 * row — a compensating row (negative amount, void_of_payment_id pointing
 * back) is written instead, and the original is stamped voided_at. Both
 * rows keep the due_before/due_after snapshot convention, so the ledger
 * stays append-only and auditable.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            $table->string('party_type', 20)->nullable()->after('is_wholesale');
            $table->string('company_name')->nullable()->after('party_type');
            $table->string('contact_person')->nullable()->after('company_name');
            $table->string('address', 500)->nullable()->after('contact_person');
            $table->text('notes')->nullable()->after('address');
        });

        Schema::table('payments', function (Blueprint $table) {
            $table->timestamp('voided_at')->nullable()->after('date');
            $table->foreignId('voided_by')->nullable()->after('voided_at')->constrained('users')->nullOnDelete();
            $table->foreignId('void_of_payment_id')->nullable()->after('voided_by')->constrained('payments')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->dropConstrainedForeignId('void_of_payment_id');
            $table->dropConstrainedForeignId('voided_by');
            $table->dropColumn('voided_at');
        });

        Schema::table('customers', function (Blueprint $table) {
            $table->dropColumn(['party_type', 'company_name', 'contact_person', 'address', 'notes']);
        });
    }
};
