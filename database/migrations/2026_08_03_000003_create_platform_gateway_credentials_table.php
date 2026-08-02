<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('platform_gateway_credentials', function (Blueprint $table) {
            $table->id();
            // one row per provider, platform-wide (Zaylotix's OWN merchant
            // account) -- completely separate from PaymentGatewayCredential,
            // which is each shop's own account for accepting money from
            // their customers. This is for shop owners paying Zaylotix.
            $table->string('provider')->unique();
            $table->text('credentials'); // encrypted:array cast, see PlatformGatewayCredential
            $table->boolean('is_active')->default(false);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('platform_gateway_credentials');
    }
};
