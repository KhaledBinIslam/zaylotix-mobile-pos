<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('purchases', function (Blueprint $table) {
            // Kept alongside the existing free-text `supplier` string — that
            // stays available for a one-off/freeform entry when the shop
            // doesn't want to create a full supplier record.
            $table->foreignId('supplier_id')->nullable()->after('supplier')->constrained()->nullOnDelete();

            // Present only when this purchase also moved stock (a purchase
            // can still be money-only, e.g. paying a utility bill, by
            // leaving these null) — see StockIn::apply().
            $table->foreignId('product_id')->nullable()->after('memo')->constrained()->nullOnDelete();
            $table->unsignedInteger('qty')->nullable()->after('product_id');
        });
    }

    public function down(): void
    {
        Schema::table('purchases', function (Blueprint $table) {
            $table->dropConstrainedForeignId('supplier_id');
            $table->dropConstrainedForeignId('product_id');
            $table->dropColumn('qty');
        });
    }
};
