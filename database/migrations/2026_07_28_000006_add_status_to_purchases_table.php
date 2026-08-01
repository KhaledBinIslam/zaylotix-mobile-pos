<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A formal purchase-order workflow, layered on the existing instant-purchase
 * flow rather than replacing it: 'received' (the default, and the only
 * status that ever existed before this) keeps today's exact behavior — cash/
 * bank and stock move the moment it's recorded. 'pending' defers both:
 * nothing moves until PurchaseController::markReceived() is called later,
 * for shops that actually place an order and wait for delivery instead of
 * paying cash-on-the-spot.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('purchases', function (Blueprint $table) {
            $table->string('status')->default('received')->after('method');
            // for a 'pending' purchase, the cost/batch/serial details typed
            // at order time have nowhere else to live until markReceived()
            // actually applies them — a 'received' purchase never needs this,
            // its effects already landed at creation time same as before
            $table->json('pending_details')->nullable()->after('status');
        });
    }

    public function down(): void
    {
        Schema::table('purchases', function (Blueprint $table) {
            $table->dropColumn(['status', 'pending_details']);
        });
    }
};
