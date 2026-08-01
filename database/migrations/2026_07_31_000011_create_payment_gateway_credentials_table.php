<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Bring-your-own-key payment gateway credentials — each shop owner supplies
 * their OWN bKash/Nagad/SSLCommerz merchant API key/secret; this app never
 * ships a shared platform-wide merchant account. `credentials` is a JSON
 * blob (shape varies per provider — see App\Support\Gateways\*Driver) and
 * is stored via Eloquent's `encrypted:array` cast (App\Models\PaymentGatewayCredential),
 * meaning it's encrypted with the app's own APP_KEY before ever touching
 * the database — a raw SQL dump/backup of this table is never enough on
 * its own to recover a shop's live merchant secret.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payment_gateway_credentials', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shop_id')->constrained()->cascadeOnDelete();
            $table->enum('provider', ['bkash', 'nagad', 'sslcommerz']);
            $table->text('credentials'); // encrypted JSON — see PaymentGatewayCredential's cast
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['shop_id', 'provider']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_gateway_credentials');
    }
};
