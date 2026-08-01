<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A "held cart" is a parked, not-yet-checked-out sale — a cashier serving
 * customer A can park A's half-built cart, serve customer B to completion,
 * then come back and resume A's cart exactly where it was left. This is
 * pure client-side cart state until checkout normally; holding it just
 * means persisting that snapshot server-side instead of losing it if the
 * cashier navigates away or another customer's checkout needs the screen.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('held_carts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shop_id')->constrained()->cascadeOnDelete();
            $table->string('label')->nullable();
            $table->json('cart_data');
            $table->timestamps();

            $table->index('shop_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('held_carts');
    }
};
