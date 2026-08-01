<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One row per gateway checkout attempt — the cart is validated and priced
 * exactly like a normal checkout up front, then snapshotted into
 * `checkout_payload` so the *actual* Sale is only ever created once the
 * gateway's webhook confirms the money genuinely arrived (never on the
 * strength of the browser redirect alone, which a customer can close,
 * retry, or fake). `reference` is this app's own idempotency key, sent to
 * the gateway and echoed back in its callback — that round trip is what
 * lets the webhook find its way back to the right pending payment without
 * ever trusting a shop_id/amount the callback itself claims to carry.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('gateway_payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shop_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->enum('provider', ['bkash', 'nagad', 'sslcommerz']);
            $table->string('reference')->unique();
            $table->string('gateway_transaction_id')->nullable();
            $table->decimal('amount', 12, 2);
            $table->enum('status', ['pending', 'completed', 'failed'])->default('pending');
            $table->json('checkout_payload');
            $table->foreignId('sale_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamps();

            $table->index(['shop_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('gateway_payments');
    }
};
