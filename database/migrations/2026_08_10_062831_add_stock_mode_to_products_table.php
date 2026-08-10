<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Cooked food (biryani, curry — a pot's yield isn't known ahead of
     * time) doesn't fit the numeric-stock model every other vertical
     * uses, but bottled/packaged restaurant items (coke, ice cream) do —
     * this needs to be a per-product choice, not a per-shop or
     * per-vertical one.
     *
     *  - 'tracked'   (default, existing behavior, unaffected): stock is a
     *    real count, checked and decremented on every sale — packaged
     *    goods, or any dish an owner deliberately wants to cap.
     *  - 'untracked': always sellable, stock never checked or touched.
     *  - 'toggle': a plain available/sold-out switch, not a count — see
     *    Product::stock's dual meaning under this mode in the model.
     *
     * Defaulting every existing row to 'tracked' changes nothing for any
     * shop until they explicitly pick a different mode on a product.
     */
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->string('stock_mode')->default('tracked')->after('stock');
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn('stock_mode');
        });
    }
};
