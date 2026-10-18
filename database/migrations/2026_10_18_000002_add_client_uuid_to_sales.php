<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sales', function (Blueprint $table) {
            // Idempotency key the client generates once per checkout attempt
            // (see PosController::performCheckout()) — lets a replayed
            // offline-queue sync, or a lost response on a normal online
            // checkout, return the sale that already exists instead of
            // creating a duplicate. Nullable + unique only per-shop: an
            // older cached frontend build that never sends one just leaves
            // this null, same as every pre-existing sale.
            $table->uuid('client_uuid')->nullable()->after('id');
            $table->unique(['shop_id', 'client_uuid']);
        });
    }

    public function down(): void
    {
        Schema::table('sales', function (Blueprint $table) {
            $table->dropUnique(['shop_id', 'client_uuid']);
            $table->dropColumn('client_uuid');
        });
    }
};
