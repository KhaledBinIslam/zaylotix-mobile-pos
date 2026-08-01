<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // A product's flat `stock` column stays the single source of truth
        // for "how much is on hand" (checkout, reports, valuation — nothing
        // about that changes). This table is a supplementary FEFO/expiry
        // layer on top: when a shop has batch tracking on, stock received
        // with a batch/expiry also lands here, checkout deducts from the
        // soonest-expiring batch first, and expiry alerts read from here.
        // Product.stock and the sum of a product's batch quantities can
        // legitimately drift apart (e.g. stock that existed before batch
        // tracking was turned on was never batched) — that's fine, batches
        // only ever describe what's been explicitly tracked.
        Schema::create('product_batches', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shop_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->string('batch_no')->nullable();
            $table->date('expiry_date')->nullable();
            $table->unsignedInteger('qty'); // remaining qty in base units
            $table->decimal('cost', 12, 2)->nullable();
            $table->timestamps();

            $table->index(['shop_id', 'product_id']);
            $table->index('expiry_date');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_batches');
    }
};
