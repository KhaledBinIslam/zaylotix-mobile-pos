<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One counter per (main shop, category code, BAND) instead of per
 * (main shop, category code).
 *
 * Categories may share a code while starting their numbers in different
 * places — in Khaled's shop Slippers and Ladies are both "L", one from
 * 90000 and one from 10000 (see product_categories.serial_start). With a
 * single row per code the second band would have inherited the first
 * one's counter and handed out a number from the wrong range.
 *
 * Existing rows take series_start = 10001, which is the value
 * ArticleSerial::START has always used, so every counter already live
 * keeps its exact identity and its exact next_serial — this migration
 * cannot move a single article number.
 */
return new class extends Migration
{
    private const INDEX = 'asc_main_shop_code_band_unique';

    public function up(): void
    {
        Schema::table('article_series_counters', function (Blueprint $table) {
            $table->unsignedInteger('series_start')->default(10001)->after('category_code');
        });

        /*
         * Order matters on MySQL: main_shop_id's foreign key needs SOME
         * index starting with that column, and the old unique one was it
         * — dropping it first fails with "needed in a foreign key
         * constraint". Adding the wider unique index first leaves the FK
         * covered throughout, so the drop then has nothing to complain
         * about. Two statements rather than one because a single
         * Schema::table emits them in declaration order anyway, and this
         * way the sequence is explicit.
         */
        Schema::table('article_series_counters', function (Blueprint $table) {
            // named explicitly: the generated name would be
            // article_series_counters_main_shop_id_category_code_series_start_unique,
            // 68 characters, and MySQL refuses an identifier over 64
            $table->unique(['main_shop_id', 'category_code', 'series_start'], self::INDEX);
        });

        Schema::table('article_series_counters', function (Blueprint $table) {
            $table->dropUnique(['main_shop_id', 'category_code']);
        });
    }

    public function down(): void
    {
        Schema::table('article_series_counters', function (Blueprint $table) {
            $table->dropUnique(self::INDEX);
        });

        /*
         * Collapsing back to one row per code can only be done safely if
         * no code ever grew a second band — otherwise the unique index
         * refuses to build and a rollback becomes a failed migration on a
         * live database. Keep the highest counter per code and drop the
         * rest, which is the only merge that cannot hand out a number
         * twice.
         */
        $rows = \Illuminate\Support\Facades\DB::table('article_series_counters')
            ->select('main_shop_id', 'category_code', \Illuminate\Support\Facades\DB::raw('MAX(next_serial) as keep'))
            ->groupBy('main_shop_id', 'category_code')
            ->get();

        \Illuminate\Support\Facades\DB::table('article_series_counters')->delete();
        foreach ($rows as $row) {
            \Illuminate\Support\Facades\DB::table('article_series_counters')->insert([
                'main_shop_id' => $row->main_shop_id,
                'category_code' => $row->category_code,
                'next_serial' => $row->keep,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        Schema::table('article_series_counters', function (Blueprint $table) {
            $table->dropColumn('series_start');
            $table->unique(['main_shop_id', 'category_code']);
        });
    }
};
