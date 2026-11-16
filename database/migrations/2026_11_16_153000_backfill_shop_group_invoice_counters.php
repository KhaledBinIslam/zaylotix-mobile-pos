<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * One-time data fix for PosController::performCheckout()'s invoice-number
 * change (now resolves to Shop::invoiceCounterShop(), i.e. the main/parent
 * shop, so every branch of one business shares one running counter instead
 * of each branch independently starting from 1000 — real live report: two
 * sibling branches both printed "INV-1007").
 *
 * Existing branches already have divergent counters. Switching the code to
 * "always read/write the parent's counter" without this backfill would
 * make the parent's (possibly much smaller) counter the one used by every
 * branch going forward — immediately re-colliding with invoice numbers
 * those branches already issued. This sets each parent's counter to the
 * MAX already reached by itself + all its branches, so the shared sequence
 * only ever continues forward from here, never backward.
 */
return new class extends Migration
{
    public function up(): void
    {
        $parentIds = DB::table('shops')->whereNotNull('parent_shop_id')->distinct()->pluck('parent_shop_id');

        foreach ($parentIds as $parentId) {
            $groupShopIds = DB::table('shops')
                ->where('id', $parentId)
                ->orWhere('parent_shop_id', $parentId)
                ->pluck('id');

            foreach (['invoice_counter', 'wholesale_invoice_counter'] as $column) {
                $max = DB::table('shops')->whereIn('id', $groupShopIds)->max($column);
                if ($max !== null) {
                    DB::table('shops')->where('id', $parentId)->update([$column => $max]);
                }
            }
        }
    }

    public function down(): void
    {
        // No-op — this only ever raises a counter to a max already in use
        // by a real branch, never introduces data that needs undoing.
    }
};
