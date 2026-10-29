<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Overrides the shop name printed on barcode stickers -- e.g. a business
 * with two branches ("Baby Park" main + "Liberty" branch) that wants a
 * combined "Baby Park & Liberty" header on every sticker, regardless of
 * which branch printed it, without renaming either shop account. Lives
 * only on a main shop (null parent_shop_id); a branch's own sticker
 * header always resolves through its main shop (see Shop::labelHeader()),
 * so there's one source of truth per business, not one per shop. Null
 * (every shop today) falls back to that shop's own name -- zero behavior
 * change until an owner sets one.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('shops', function (Blueprint $table) {
            $table->string('label_header')->nullable()->after('name_en');
        });
    }

    public function down(): void
    {
        Schema::table('shops', function (Blueprint $table) {
            $table->dropColumn('label_header');
        });
    }
};
