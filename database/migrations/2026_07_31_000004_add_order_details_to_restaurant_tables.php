<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Restaurant order module additions:
 *  - order_source/delivery_platform: dine-in is the default (matches every
 *    existing order); takeaway/delivery let a shop tag an order as coming
 *    from a 3rd-party platform (Food Panda, Pathao Food, ...) or typed in
 *    free-text — a fixed list would go stale as new platforms show up.
 *  - kitchen_note: one free-text instruction for the whole order (e.g.
 *    "কম ঝাল") — deliberately order-level, not per-item, to keep the UI
 *    simple; printed on the KOT and included in the kitchen WhatsApp send.
 *  - served_at (per item): lets the counter track which items have
 *    actually reached the table, separate from kot_printed_at (sent to
 *    kitchen) — a busy kitchen can be mid-prep on an item that's already
 *    been printed but not yet served.
 *  - shops.kitchen_whatsapp: where the KOT gets sent when a shop uses
 *    WhatsApp instead of (or alongside) a physical kitchen printer —
 *    separate from shops.phone, which is the shop's own printed contact
 *    number, not a message target.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('table_orders', function (Blueprint $table) {
            $table->enum('order_source', ['dine_in', 'takeaway', 'delivery'])->default('dine_in')->after('status');
            $table->string('delivery_platform')->nullable()->after('order_source');
            $table->text('kitchen_note')->nullable()->after('delivery_platform');
        });

        Schema::table('table_order_items', function (Blueprint $table) {
            $table->timestamp('served_at')->nullable()->after('kot_printed_at');
        });

        Schema::table('shops', function (Blueprint $table) {
            $table->string('kitchen_whatsapp')->nullable()->after('phone');
        });
    }

    public function down(): void
    {
        Schema::table('shops', function (Blueprint $table) {
            $table->dropColumn('kitchen_whatsapp');
        });
        Schema::table('table_order_items', function (Blueprint $table) {
            $table->dropColumn('served_at');
        });
        Schema::table('table_orders', function (Blueprint $table) {
            $table->dropColumn(['order_source', 'delivery_platform', 'kitchen_note']);
        });
    }
};
