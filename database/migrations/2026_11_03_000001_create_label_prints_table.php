<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A row per sticker run, so the shop can see what it has already
 * labelled.
 *
 * Khaled: "kongular barcode print korchi kongular koreni track korle
 * show korle valo hoi" — after a delivery there is no way to tell which
 * sizes have had their stickers printed and which were missed, so he was
 * re-printing whole articles to be sure.
 *
 * A NEW table on purpose, not columns on product_variants: that table
 * carries live stock for 1,400 rows across two shops, and this is a
 * convenience feature. Nothing existing has to change shape for it, and
 * if it were ever dropped nothing else would notice.
 *
 * What is recorded is "sent to the printer", which is all the app can
 * honestly know — paper can still jam. That is why the count is kept
 * rather than a boolean: a second run against the same size is visible
 * rather than silently indistinguishable from the first.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('label_prints', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shop_id')->constrained()->cascadeOnDelete();

            /*
             * The BARCODE is the key, not the row id.
             *
             * A sticker is a barcode, and CatalogSync keeps a variant's
             * barcode identical across every shop of a business on
             * purpose — while the row ids differ. Keying on the id would
             * tell Liberty a shoe still needs a sticker that Baby Park
             * printed and stuck on it an hour ago.
             */
            $table->string('barcode', 64)->index();

            // kept for traceability — which row, who, when — but never
            // what the lookup matches on
            $table->foreignId('product_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('product_variant_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('product_color_barcode_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->unsignedInteger('copies')->default(1);
            $table->timestamps();

            // the page asks "when was each of these last printed" for a
            // whole screen of rows at once
            $table->index(['shop_id', 'barcode']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('label_prints');
    }
};
