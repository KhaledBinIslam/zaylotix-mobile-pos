<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            // Same idempotency pattern PosController::checkout already uses
            // via Sale.client_uuid — a cashier double-tapping "কালেক্ট" on a
            // slow connection (real-world finding, 2026-11-15 audit) must
            // return the original payment instead of creating a second one.
            // Nullable + unique: a legacy/untagged payment keeps client_uuid
            // null (MySQL allows any number of NULLs in a unique index).
            $table->string('client_uuid')->nullable()->unique()->after('date');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->dropUnique(['client_uuid']);
            $table->dropColumn('client_uuid');
        });
    }
};
