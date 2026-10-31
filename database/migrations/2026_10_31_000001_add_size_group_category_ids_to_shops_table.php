<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Which categories price by size group (the 3-price Small/Medium/Large
 * flow) instead of one flat price.
 *
 * Until now that was decided by matching the category's NAME against a
 * hard-coded pair, "Kids' Shoes" / "বাচ্চাদের জুতা". Baby Park's own
 * category is called "BABY", so the three price boxes never appeared for
 * them — the feature was built for that shop and silently didn't apply to
 * it. A name is the owner's to choose; this makes the behaviour theirs to
 * choose too.
 *
 * Nullable: null keeps the old name-matching exactly as it was, so no
 * existing shop changes until someone ticks a category here.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('shops', function (Blueprint $table) {
            // [12, 15] — product_categories ids
            $table->json('size_group_category_ids')->nullable()->after('kids_shoe_size_groups');
        });
    }

    public function down(): void
    {
        Schema::table('shops', function (Blueprint $table) {
            $table->dropColumn('size_group_category_ids');
        });
    }
};
