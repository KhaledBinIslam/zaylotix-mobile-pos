<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A shared, platform-level (NOT shop-scoped — no shop_id at all) reference
 * catalog of medicine name/generic/company/form, seeded with a starter set
 * of well-known Bangladeshi pharmaceutical products. This is a lookup
 * helper for quickly creating a Product from a known name — it deliberately
 * carries no price/stock (that's each shop's own business, differs store
 * to store, and nothing here should be mistaken for live market pricing).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('medicine_catalog', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('generic_name');
            $table->string('company');
            $table->string('form')->nullable(); // Tablet, Syrup, Capsule, Injection, ...
            $table->string('strength')->nullable(); // "500mg", "5ml", ...
            $table->timestamps();

            $table->index('name');
            $table->index('generic_name');
            $table->index('company');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('medicine_catalog');
    }
};
