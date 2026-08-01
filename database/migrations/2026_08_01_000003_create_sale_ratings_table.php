<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sale_ratings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shop_id')->constrained()->cascadeOnDelete();
            // unique: a customer scanning the same receipt's QR code twice
            // updates their one rating rather than creating duplicates
            $table->foreignId('sale_id')->unique()->constrained()->cascadeOnDelete();
            $table->unsignedTinyInteger('stars');
            $table->string('comment')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sale_ratings');
    }
};
