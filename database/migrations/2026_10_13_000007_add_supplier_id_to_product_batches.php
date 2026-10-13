<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Closes a real traceability gap: a batch created via a supplier-linked
 * purchase (PurchaseController::applyEffects -> BatchStock::receive) never
 * recorded which supplier it came from — "which supplier sent us the batch
 * that's now expiring" had no answer. Nullable and backward compatible: a
 * batch received via a plain stock-in (no purchase involved) simply has no
 * supplier, exactly as before this column existed.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('product_batches', function (Blueprint $table) {
            $table->foreignId('supplier_id')->nullable()->after('product_id')->constrained()->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('product_batches', function (Blueprint $table) {
            $table->dropConstrainedForeignId('supplier_id');
        });
    }
};
