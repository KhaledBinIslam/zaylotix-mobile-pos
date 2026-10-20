<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 3, feature 1 — "সিজন ক্যাম্পেইন কিট" (see docs/FIX_PLAN.md's design
 * note). Deliberately two small tables rather than inventing a parallel
 * promotion/messaging system: a `season` is just a named date window the
 * owner defines once ("ঈদুল ফিতর ২০২৭", "পৌষ পার্বণ") and reuses year to
 * year; a `campaign` is a saved bundle of (an existing `promotions` row +
 * a customer segment + a message template) under that season, so launching
 * it is just activating the already-built Promotion engine and opening the
 * already-built WhatsApp send queue with the right people and text — no
 * new discount logic, no new messaging logic.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('seasons', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shop_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            // free-text, shown as a small badge on the campaign list — not
            // a fixed enum, since the whole point is a shop in any vertical
            // (clothing's "সিজন মার্কডাউন" vs grocery's "রমজান বান্ডেল") can
            // name whatever season actually applies to them
            $table->string('tag')->nullable();
            $table->date('starts_at')->nullable();
            $table->date('expires_at')->nullable();
            $table->timestamps();

            $table->index(['shop_id', 'starts_at']);
        });

        Schema::create('campaigns', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shop_id')->constrained()->cascadeOnDelete();
            $table->foreignId('season_id')->nullable()->constrained()->nullOnDelete();
            // nullable: a campaign can be message-only (no discount
            // attached, e.g. a plain "আমরা ফিরে এসেছি" reminder)
            $table->foreignId('promotion_id')->nullable()->constrained()->nullOnDelete();
            $table->string('name');
            // matches the segment keys CustomerList/Index.vue's filter
            // already uses (see resources/js/support/customerSegments.js) —
            // 'all' | 'topSpenders' | 'mostVisits' | 'highestDue' | 'inactive'
            $table->string('segment')->default('all');
            $table->text('message_template')->nullable();
            $table->timestamp('last_launched_at')->nullable();
            $table->timestamps();

            $table->index(['shop_id', 'season_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('campaigns');
        Schema::dropIfExists('seasons');
    }
};
