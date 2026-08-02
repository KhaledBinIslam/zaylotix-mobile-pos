<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('subscription_payments', function (Blueprint $table) {
            // traces an auto-recorded renewal (owner paid via bKash/SSLCommerz
            // themselves) back to the GatewayPayment that triggered it -- null
            // for every existing/admin-manually-entered row, exactly as before
            $table->foreignId('gateway_payment_id')->nullable()->after('admin_id')->constrained()->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('subscription_payments', function (Blueprint $table) {
            $table->dropConstrainedForeignId('gateway_payment_id');
        });
    }
};
