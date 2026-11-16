<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('rental_returns', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shop_id')->constrained()->cascadeOnDelete();
            $table->foreignId('rental_id')->constrained()->cascadeOnDelete();
            $table->foreignId('rental_item_id')->constrained()->cascadeOnDelete();
            $table->decimal('qty', 10, 2);
            // resalable, damaged, lost
            $table->string('condition')->default('resalable');
            $table->decimal('late_fee', 10, 2)->default(0);
            $table->decimal('damage_charge', 10, 2)->default(0);
            $table->date('date');
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->text('notes')->nullable();
            // Idempotency key for the whole processReturn() request — NOT
            // unique, since one request writes one row per line, all
            // sharing the same key. A resubmit is caught by an existence
            // check (see RentalController::processReturn()), not a unique
            // constraint, precisely because this is one-to-many.
            $table->uuid('client_uuid')->nullable();
            $table->timestamps();

            $table->index(['shop_id', 'rental_id']);
            $table->index(['shop_id', 'client_uuid']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('rental_returns');
    }
};
