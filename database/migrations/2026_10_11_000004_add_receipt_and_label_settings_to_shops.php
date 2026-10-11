<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * New shop-identity fields (email/website/trade license/TIN) default to
 * null, and the two settings blobs default to null too -- the frontend/
 * backend both treat a null memo_settings/label_settings as "every new
 * toggle off, everything else exactly as it already prints today" (see
 * Shop::memoSettings()/labelSettings()), so an existing shop's printed
 * memo and barcode label are byte-for-byte unchanged until its owner
 * opens the new settings page and turns something on.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('shops', function (Blueprint $table) {
            $table->string('email')->nullable()->after('phone');
            $table->string('website')->nullable()->after('email');
            $table->string('trade_license_no')->nullable()->after('bin_no');
            $table->string('tin_no')->nullable()->after('trade_license_no');
            $table->json('memo_settings')->nullable()->after('receipt_footer');
            $table->json('label_settings')->nullable()->after('memo_settings');
        });
    }

    public function down(): void
    {
        Schema::table('shops', function (Blueprint $table) {
            $table->dropColumn(['email', 'website', 'trade_license_no', 'tin_no', 'memo_settings', 'label_settings']);
        });
    }
};
