<?php

namespace App\Support;

use App\Models\Customer;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Sale;
use App\Models\Shop;

/**
 * Undoes exactly what checkout did: gives stock back, and rolls back
 * whatever it added to cash/bank balance or a customer's due/spend history.
 * Shared by the admin's hard-delete tool (ShopDataController::destroySale)
 * and the shop-level owner-void route — the reversal math must live in
 * exactly one place so the two can't drift apart.
 *
 * Must be called from inside a DB::transaction() with the shop row already
 * locked by the caller (Shop::whereKey($id)->lockForUpdate()->first()) —
 * this mirrors PosController::checkout's own locking discipline so a
 * reversal can't race a live sale on the same product/customer/shop row.
 */
class SaleReversal
{
    public static function reverse(Sale $sale, Shop $lockedShop): void
    {
        $sale->loadMissing(['items', 'payments']);

        foreach ($sale->items as $item) {
            if ($item->product_id) {
                Product::whereKey($item->product_id)->lockForUpdate()->increment('stock', $item->qty * $item->unit_factor);

                if ($item->product_variant_id) {
                    // paired with the paired decrement in PosController::checkout
                    // — keeps products.stock === sum(variants.stock) intact
                    ProductVariant::whereKey($item->product_variant_id)->lockForUpdate()->increment('stock', $item->qty);
                } else {
                    // puts each unit back into the exact batch checkout took
                    // it from (see PosController::checkout + BatchStock::deduct)
                    // — a no-op when the sale predates batch tracking, wasn't
                    // batch-covered, or was a variant line (batches aren't
                    // variant-aware)
                    BatchStock::restore($item->batch_allocations);

                    // flips the specific IMEI back to in_stock so it can be
                    // resold and no longer shows as "sold" in warranty lookup
                    SerialStock::restore($item->product_serial_id);
                }
            }
        }

        $customer = $sale->customer_id ? Customer::whereKey($sale->customer_id)->lockForUpdate()->first() : null;

        if ($sale->payments->isNotEmpty()) {
            // Post-split-payment sale: reverse each tender line into its own
            // bucket, then whatever wasn't covered by tenders (the due
            // remainder, if any) comes back off the customer.
            foreach ($sale->payments as $payment) {
                if ($payment->method === 'cash') {
                    $lockedShop->cash_balance = max(0, $lockedShop->cash_balance - $payment->amount);
                } else {
                    $lockedShop->bank_balance = max(0, $lockedShop->bank_balance - $payment->amount);
                }
            }

            $tendered = (float) $sale->payments->sum('amount');
            $dueRemainder = (float) $sale->total - $tendered;
            if ($customer && $dueRemainder > 0) {
                $customer->due = max(0, $customer->due - $dueRemainder);
            }
        } else {
            // Legacy pre-split-payment sale (or a pure-credit sale, which
            // never gets a sale_payments row since due isn't a tender) —
            // reverse via the single payment_mode exactly as before.
            if ($sale->payment_mode === 'cash') {
                $lockedShop->cash_balance = max(0, $lockedShop->cash_balance - $sale->total);
            } elseif (in_array($sale->payment_mode, ['bkash', 'nagad'], true)) {
                $lockedShop->bank_balance = max(0, $lockedShop->bank_balance - $sale->total);
            } elseif ($sale->payment_mode === 'credit' && $customer) {
                $customer->due = max(0, $customer->due - $sale->total);
            }
        }

        if ($customer) {
            $customer->total_spent = max(0, $customer->total_spent - $sale->total);
            $customer->visits = max(0, $customer->visits - 1);
            $customer->save();
        }

        $lockedShop->save();
    }
}
