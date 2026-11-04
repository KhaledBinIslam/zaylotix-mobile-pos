<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Khaled: "sells holei j branch e hoq baby park er 2 ta branch e owner
 * [ke] sathe sathe notification and mail jai moto" — the owner of a
 * business wants to hear about every sale, whichever branch rang it up,
 * on the bell and at an email address of their choosing.
 *
 * Both columns live on the shops row but are only ever READ off the
 * business's settings shop (the main shop), same as every other
 * business-wide setting — a branch's own values are ignored, so the two
 * Baby Park branches can't drift apart. Off/empty by default: no shop
 * hears anything it didn't ask for.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('shops', function (Blueprint $table) {
            $table->boolean('notify_owner_on_sale')->default(false)->after('offline_sync_mode');
            $table->string('sale_notification_email')->nullable()->after('notify_owner_on_sale');
        });
    }

    public function down(): void
    {
        Schema::table('shops', function (Blueprint $table) {
            $table->dropColumn(['notify_owner_on_sale', 'sale_notification_email']);
        });
    }
};
