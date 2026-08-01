<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Two more owner-configurable restaurant-workflow preferences, on top of the
 * order_source/kitchen_note/kitchen_whatsapp set added in
 * add_order_details_to_restaurant_tables:
 *  - payment_timing: whether this shop collects payment before serving
 *    (pay_first — common for small food-shop counters/takeaway) or after
 *    (pay_later — the "eat first, bill at the end" dine-in norm this app's
 *    order-then-bill flow was originally built around). Purely a UI
 *    preference — it reorders/relabels the Order screen's kitchen-send vs
 *    bill-now actions, it does not block billing at any particular time
 *    (a cashier can always bill early or send to kitchen late regardless).
 *  - kitchen_print_order: when the combined print (kitchen copy + customer
 *    memo) fires from Sales/Show.vue, which copy comes out first. Some
 *    shops hand the kitchen copy to the runner immediately and want it on
 *    top; others want the customer's copy first off the printer.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('shops', function (Blueprint $table) {
            $table->enum('payment_timing', ['pay_first', 'pay_later'])->default('pay_later')->after('kitchen_whatsapp');
            $table->enum('kitchen_print_order', ['kitchen_first', 'customer_first'])->default('kitchen_first')->after('payment_timing');
        });
    }

    public function down(): void
    {
        Schema::table('shops', function (Blueprint $table) {
            $table->dropColumn(['payment_timing', 'kitchen_print_order']);
        });
    }
};
