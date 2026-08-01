<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('reservations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shop_id')->constrained()->cascadeOnDelete();
            // nullable — a reservation can be taken before deciding which
            // table, or for a shop that doesn't track specific tables at all
            $table->foreignId('restaurant_table_id')->nullable()->constrained()->nullOnDelete();
            $table->string('name');
            $table->string('phone')->nullable();
            $table->dateTime('reservation_at');
            $table->unsignedSmallInteger('guest_count')->default(1);
            $table->string('note')->nullable();
            $table->decimal('advance', 10, 2)->default(0);
            $table->enum('status', ['reserved', 'seated', 'cancelled', 'no_show'])->default('reserved');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('reservations');
    }
};
