<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sales', function (Blueprint $table) {
            // A void keeps the row (unlike the admin's hard-delete tool) so
            // the invoice stays visible/auditable with a reason attached —
            // the financial effects are reversed exactly like a delete, but
            // the record itself survives for accountability.
            $table->timestamp('voided_at')->nullable()->after('payment_mode');
            $table->string('voided_reason')->nullable()->after('voided_at');
            $table->foreignId('voided_by')->nullable()->after('voided_reason')->constrained('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('sales', function (Blueprint $table) {
            $table->dropConstrainedForeignId('voided_by');
            $table->dropColumn(['voided_at', 'voided_reason']);
        });
    }
};
