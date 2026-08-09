<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Bring-your-own-key WhatsApp Business (Meta Cloud API) connection — each
 * shop supplies their OWN Phone Number ID + permanent access token from
 * their own Meta Business/WhatsApp Business Platform account; this app
 * never ships a shared platform-wide WhatsApp sender number. One row per
 * shop (not per-provider like payment gateways — there's only one WhatsApp
 * Cloud API). `credentials` is encrypted at rest with the app's own
 * APP_KEY, same pattern as PaymentGatewayCredential.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('whatsapp_credentials', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shop_id')->unique()->constrained()->cascadeOnDelete();
            $table->text('credentials'); // encrypted JSON: phone_number_id, access_token, waba_id
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('whatsapp_credentials');
    }
};
