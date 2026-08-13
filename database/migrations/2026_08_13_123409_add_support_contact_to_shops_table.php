<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Distinct from the existing owner_name (that's the SHOP owner's own
        // name, collected at signup) — this is "who to contact for support
        // on this shop's account", shown in that shop's own app footer
        // (see AppLayout.vue), admin-set per shop, blank by default so a
        // shop with nothing set here just shows no line at all rather than
        // a stale/wrong default. Was previously hardcoded to Khaled's own
        // name/number for every shop, including ones that aren't his.
        Schema::table('shops', function (Blueprint $table) {
            $table->string('support_contact_name')->nullable()->after('owner_name');
            $table->string('support_contact_phone')->nullable()->after('support_contact_name');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('shops', function (Blueprint $table) {
            $table->dropColumn(['support_contact_name', 'support_contact_phone']);
        });
    }
};
