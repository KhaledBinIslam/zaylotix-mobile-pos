<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('business_types', function (Blueprint $table) {
            $table->id();
            $table->string('slug')->unique(); // supershop, grocery, clothing, pharmacy, mobile, cosmetics, general
            $table->string('label_bn');
            $table->string('label_en');
            $table->json('fields')->nullable(); // enabled optional product fields: expiry, batch, imei, size, color
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('business_types');
    }
};
