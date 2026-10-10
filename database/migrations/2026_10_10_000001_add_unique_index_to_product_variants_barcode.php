<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Backs the "barcode must be unique in the shop" requirement with a
     * real DB constraint, not just the generator's own by-construction
     * uniqueness (ZV + the variant's own id) — a manually TYPED barcode
     * could still collide otherwise. Scoped to (shop_id, barcode), not a
     * bare unique on barcode alone — this is a multi-tenant SaaS, and two
     * unrelated shops both typing the same simple code (e.g. "12345") is
     * their own business, not something this app should block across
     * tenants. MySQL's unique index already treats multiple NULLs as
     * distinct, so every variant without a barcode yet is unaffected;
     * confirmed no existing duplicate (shop_id, barcode) pair before
     * writing this.
     */
    public function up(): void
    {
        Schema::table('product_variants', function (Blueprint $table) {
            $table->unique(['shop_id', 'barcode']);
        });
    }

    public function down(): void
    {
        Schema::table('product_variants', function (Blueprint $table) {
            $table->dropUnique(['shop_id', 'barcode']);
        });
    }
};
