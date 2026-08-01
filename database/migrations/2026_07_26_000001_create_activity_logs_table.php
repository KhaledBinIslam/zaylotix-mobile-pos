<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('activity_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shop_id')->constrained()->cascadeOnDelete();
            // nullOnDelete, not cascade — a staff account being removed later
            // must not erase the record that they, e.g., voided a sale
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();

            $table->string('action'); // e.g. 'sale.void', 'product.delete', 'payment.collect'
            $table->string('subject_type')->nullable();
            $table->unsignedBigInteger('subject_id')->nullable();
            $table->string('description'); // human-readable Bengali line shown in the activity list
            $table->json('meta')->nullable(); // amounts/reason/before-after, action-specific

            $table->timestamp('created_at')->nullable(); // append-only — no updated_at

            $table->index(['shop_id', 'created_at']);
            $table->index(['subject_type', 'subject_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('activity_logs');
    }
};
