<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * docs/FIX_PLAN.md's "বিলিং দ্রুত করা... সার্চ" ticket, ধাপ ২.ঘ —
 * `search_text` is the normalized (SearchNormalizer::normalize(), see
 * Product::refreshSearchText()) concatenation of every field a cashier
 * might actually type: name, name_en, generic_name, barcode,
 * search_keywords. Every search query goes through the exact same
 * normalizer before comparing, so a Unicode spelling mismatch (য়/ড়/ঢ়,
 * Bengali digits, …) can never cause a false "not found" again.
 *
 * `search_keywords` is a separate, owner-editable free-text field
 * (alternate names/spellings/Banglish, e.g. "soya sauce, সয়া সস,
 * ধনেপাতা, dhonia") that folds into search_text alongside the regular
 * name fields — additive, nullable, never required.
 *
 * Plain TEXT columns, no index: a leading-wildcard LIKE '%…%' can't use a
 * btree index anyway, and a utf8mb4 index on a column this wide risks
 * hitting MySQL's index key-length limit on shared hosting. The existing
 * `shop_id` index already scopes every query; if a genuinely large
 * catalog ever needs this faster, MySQL FULLTEXT is the next step (not
 * built now — no real shop is close to needing it, see
 * docs/SCALING_GUIDE.md's existing "only build what today's data proves
 * is needed" convention).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->text('search_keywords')->nullable()->after('generic_name');
            $table->text('search_text')->nullable()->after('search_keywords');
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn(['search_keywords', 'search_text']);
        });
    }
};
