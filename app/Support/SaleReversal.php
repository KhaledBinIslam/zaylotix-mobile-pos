<?php

namespace App\Support;

use App\Models\ActivityLog;
use App\Models\Customer;
use App\Models\Payment;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Promotion;
use App\Models\Quotation;
use App\Models\Sale;
use App\Models\SalesReturn;
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
 *
 * NET of any return/exchange already processed against the sale (see
 * priorReturns): a return has itself already given stock and money back,
 * so crediting the FULL original sale on top of that would double-credit
 * both. Every branch below is identical to its pre-netting behavior when
 * the sale has no returns at all — which is every sale the three callers
 * (shop void, admin hard-delete, online-order cancel) have ever reversed
 * until now, since void simply refused a returned bill outright.
 */
class SaleReversal
{
    public static function reverse(Sale $sale, Shop $lockedShop): void
    {
        $sale->loadMissing(['items', 'payments']);

        $prior = self::priorReturns($sale);

        foreach ($sale->items as $item) {
            if ($item->product_id) {
                // Only the units NOT already returned are still owed back to
                // this shop's stock. A returned unit was accounted for once
                // already — either put back on the shelf (resalable) or
                // written off as Damage (damaged/lost) — so crediting it
                // again here would invent stock that doesn't exist.
                $returnedQty = (float) ($prior['qtyByItem'][$item->id] ?? 0);
                $remainingQty = (float) $item->qty - $returnedQty;
                if ($remainingQty <= 0.0001) {
                    continue; // fully returned already — nothing left to give back
                }

                $product = Product::whereKey($item->product_id)->lockForUpdate()->first();
                $product?->applyStockDelta($remainingQty * $item->unit_factor);

                if ($item->product_variant_id) {
                    // paired with the paired decrement in PosController::checkout
                    // — keeps products.stock === sum(variants.stock) intact
                    $variant = ProductVariant::whereKey($item->product_variant_id)->lockForUpdate()->first();
                    $variant?->applyStockDelta($remainingQty);
                } else {
                    // puts each unit back into the exact batch checkout took
                    // it from (see PosController::checkout + BatchStock::deduct)
                    // — a no-op when the sale predates batch tracking, wasn't
                    // batch-covered, or was a variant line (batches aren't
                    // variant-aware)
                    if ($returnedQty > 0.0001) {
                        // A prior return already restored its own resalable
                        // share of these same allocations (via
                        // BatchStock::restorePartial in ReturnController) —
                        // skip past that share and restore only what's left,
                        // or those batch rows get credited twice.
                        BatchStock::restorePartial(
                            $item->batch_allocations,
                            (float) ($prior['resalableQtyByItem'][$item->id] ?? 0) * (float) $item->unit_factor,
                            $remainingQty * (float) $item->unit_factor,
                        );
                    } else {
                        BatchStock::restore($item->batch_allocations);
                    }

                    // flips the specific IMEI back to in_stock so it can be
                    // resold and no longer shows as "sold" in warranty lookup
                    SerialStock::restore($item->product_serial_id);
                }
            }
        }

        // What a return has already paid back out of the shop's own money.
        // Each money branch below reverses the sale MINUS this, so the
        // customer ends up owed exactly the part they never got back.
        $refunded = $prior['refund'];

        $customer = $sale->customer_id ? Customer::whereKey($sale->customer_id)->lockForUpdate()->first() : null;

        if ($sale->payments->isNotEmpty()) {
            // Post-split-payment sale: reverse each tender line into its own
            // bucket, then whatever wasn't covered by tenders (the due
            // remainder, if any) comes back off the customer.
            foreach ($sale->payments as $payment) {
                if ($payment->method === 'cash') {
                    $lockedShop->cash_balance = max(0, $lockedShop->cash_balance - $payment->amount);
                } elseif ($payment->method === 'cod') {
                    // never bumped cash/bank_balance when recorded (see
                    // docs/FIX_PLAN.md's Phase 32 design note — that money
                    // is with the courier, not the shop, until Part 3's COD
                    // settlement) — nothing to reverse here either
                } else {
                    $lockedShop->bank_balance = max(0, $lockedShop->bank_balance - $payment->amount);
                }
            }

            $tendered = (float) $sale->payments->sum('amount');
            $dueRemainder = (float) $sale->total - $tendered;

            // Split the refund already paid out the same proportional way
            // ReturnController::process itself did, so the two halves land
            // on the same two buckets the refund came out of.
            [$refundDue, $refundCash] = self::splitRefund($refunded, $dueRemainder, (float) $sale->total);

            if ($refundCash > 0) {
                // A refund leaves the drawer as cash (ReturnController only
                // ever touches cash_balance for one). The tender loop above
                // has already taken the FULL sale back out of cash/bank, so
                // without putting the refund back the shop would be debited
                // twice over the same money.
                $lockedShop->cash_balance = (float) $lockedShop->cash_balance + $refundCash;
            }

            // only the slice of the original due that a refund hasn't
            // already written off is still the customer's to clear
            $dueOutstanding = max(0, $dueRemainder - $refundDue);
            if ($customer && $dueOutstanding > 0) {
                // Phase 39: part of this bill's due may ALREADY have been
                // collected (Payment rows tagged with this sale_id). That
                // money is real and stays with the shop — per Khaled's
                // explicit decision it becomes the customer's advance/store
                // credit instead of a cash refund, spendable later from the
                // due screen ("অগ্রিম সমন্বয়"). Only the still-outstanding
                // part comes back off the running due. A collection taken
                // WITHOUT picking an invoice (sale_id null) can't be
                // attributed to this bill, so it's deliberately not counted
                // — same reasoning as PaymentController::invoices().
                $collected = min(
                    (float) Payment::withoutGlobalScopes()->where('sale_id', $sale->id)->sum('amount'),
                    $dueOutstanding
                );
                $customer->due = max(0, $customer->due - ($dueOutstanding - $collected));
                if ($collected > 0) {
                    $customer->advance_balance = (float) $customer->advance_balance + $collected;
                }
            }
        } else {
            // Legacy pre-split-payment sale (or a pure-credit sale, which
            // never gets a sale_payments row since due isn't a tender) —
            // reverse via the single payment_mode exactly as before.
            if ($sale->payment_mode === 'cash') {
                $lockedShop->cash_balance = max(0, $lockedShop->cash_balance - ((float) $sale->total - $refunded));
            } elseif (in_array($sale->payment_mode, ['bkash', 'nagad'], true)) {
                $lockedShop->bank_balance = max(0, $lockedShop->bank_balance - $sale->total);
                if ($refunded > 0) {
                    // money came IN by bKash/Nagad but its refund went OUT
                    // as cash — reversing the bill reverses both legs
                    $lockedShop->cash_balance = (float) $lockedShop->cash_balance + $refunded;
                }
            } elseif ($sale->payment_mode === 'credit' && $customer) {
                // same collected-due → advance handling as the split-payment
                // branch above — a pure-credit sale is exactly the case where
                // a later collection is most likely
                $netTotal = max(0, (float) $sale->total - $refunded);
                $collected = min(
                    (float) Payment::withoutGlobalScopes()->where('sale_id', $sale->id)->sum('amount'),
                    $netTotal
                );
                $customer->due = max(0, $customer->due - ($netTotal - $collected));
                if ($collected > 0) {
                    $customer->advance_balance = (float) $customer->advance_balance + $collected;
                }
            }
        }

        if ($customer) {
            $customer->total_spent = max(0, $customer->total_spent - $sale->total);
            $customer->visits = max(0, $customer->visits - 1);

            // Phase 39: give redeemed points back, take earned points away —
            // exactly undoing what checkout did to this customer's balance.
            // Clamped at 0: the customer may have already spent the earned
            // points elsewhere; a void must never push a balance negative.
            // A return has already clawed back its own share of the points
            // this sale earned (returns.loyalty_points_deducted), so only
            // the remainder is still this reversal's to take.
            $pointsDelta = (int) ($sale->points_redeemed ?? 0) - (int) ($sale->points_earned ?? 0) + $prior['points'];
            if ($pointsDelta !== 0) {
                $customer->loyalty_points = max(0, (int) $customer->loyalty_points + $pointsDelta);
            }

            $customer->save();

            self::reverseReferralBonus($sale, $customer, $lockedShop);
        }

        // Phase 39: give the coupon/BOGO usage counters back. promotion_ids
        // is only populated on sales created after its migration — null for
        // every older sale, whose void simply skips this (same as before).
        foreach ((array) ($sale->promotion_ids ?? []) as $promotionId) {
            Promotion::withoutGlobalScopes()
                ->whereKey($promotionId)->where('shop_id', $sale->shop_id)
                ->where('used_count', '>', 0)->decrement('used_count');
        }

        // Phase 39: a sale converted from a quotation leaves that quotation
        // stuck on 'converted', pointing at a now-dead bill — reopen it so
        // it can be quoted/converted again, as if the conversion never
        // happened. withoutGlobalScopes + explicit shop_id: this runs from
        // the admin hard-delete path too, where no tenant is set.
        Quotation::withoutGlobalScopes()
            ->where('shop_id', $sale->shop_id)->where('sale_id', $sale->id)
            ->update(['status' => 'open', 'sale_id' => null]);

        $lockedShop->save();
    }

    /**
     * Everything a return/exchange against this sale has ALREADY given
     * back, keyed the way reverse() needs it. A sale with no returns (the
     * normal case) returns all zeros, which collapses every branch above
     * to its original pre-netting form.
     *
     * withoutGlobalScopes: a cross-branch return row belongs to the
     * RECEIVING branch rather than the sale's own shop, and the admin
     * hard-delete path runs with no tenant set at all.
     */
    private static function priorReturns(Sale $sale): array
    {
        $rows = SalesReturn::withoutGlobalScopes()->where('sale_id', $sale->id)->get();

        if ($rows->isEmpty()) {
            return ['qtyByItem' => [], 'resalableQtyByItem' => [], 'refund' => 0.0, 'points' => 0];
        }

        return [
            'qtyByItem' => $rows->groupBy('sale_item_id')->map(fn ($g) => (float) $g->sum('qty'))->all(),
            'resalableQtyByItem' => $rows->where('condition', 'resalable')
                ->groupBy('sale_item_id')->map(fn ($g) => (float) $g->sum('qty'))->all(),
            'refund' => (float) $rows->sum('refund'),
            // loyalty_points_deducted is an EVENT-level number that
            // ReturnController copies onto every row of the same return —
            // summing the column would multiply it by the line count, so
            // take one value per event (one return = one created_at).
            'points' => (int) $rows->groupBy(fn ($r) => (string) $r->created_at)
                ->sum(fn ($g) => (int) ($g->first()->loyalty_points_deducted ?? 0)),
        ];
    }

    /**
     * How an already-paid refund splits between "money the shop actually
     * handed over" and "due it simply wrote off" — mirroring
     * ReturnController::process's own proportional refundDuePortion math,
     * so reversing a refunded bill credits back the same two buckets the
     * refund was taken from.
     */
    private static function splitRefund(float $refunded, float $dueRemainder, float $total): array
    {
        if ($refunded <= 0 || $total <= 0) {
            return [0.0, 0.0];
        }

        $due = $dueRemainder > 0 ? min($dueRemainder, round($refunded * $dueRemainder / $total, 2)) : 0.0;

        return [$due, round($refunded - $due, 2)];
    }

    /**
     * Khaled's Phase 39 decision: a referral bonus paid out because of this
     * sale gets clawed back — but only when voiding this sale leaves the
     * referred customer with no real (non-voided) sale at all, i.e. the
     * referral genuinely hasn't produced a paying customer anymore. If they
     * have other live sales, the bonus stands. referral_rewarded_at is
     * cleared so their next "first" sale pays the referrer again, exactly
     * like PosController's own grant condition re-evaluates it.
     *
     * The clawed-back amount comes from the grant's own Activity log row
     * (meta.points — exact even if the owner changed referral_bonus_points
     * since), falling back to the shop's current setting. Clamped at 0:
     * the referrer may have already spent the points.
     */
    private static function reverseReferralBonus(Sale $sale, Customer $customer, Shop $lockedShop): void
    {
        if (! $customer->referred_by_customer_id || ! $customer->referral_rewarded_at) {
            return;
        }

        $hasOtherLiveSale = Sale::withoutGlobalScopes()
            ->where('customer_id', $customer->id)
            ->where('id', '!=', $sale->id)
            ->whereNull('voided_at')
            ->exists();
        if ($hasOtherLiveSale) {
            return;
        }

        $grantLog = ActivityLog::withoutGlobalScopes()
            ->where('shop_id', $sale->shop_id)
            ->where('action', 'customer.referralReward')
            ->where('meta->referred_customer_id', $customer->id)
            ->latest('id')->first();
        $bonus = (int) ($grantLog->meta['points'] ?? $lockedShop->referral_bonus_points ?? 0);

        if ($bonus > 0) {
            $referrer = Customer::withoutGlobalScopes()
                ->whereKey($customer->referred_by_customer_id)->lockForUpdate()->first();
            if ($referrer) {
                $referrer->loyalty_points = max(0, (int) $referrer->loyalty_points - $bonus);
                $referrer->save();
            }
        }

        $customer->referral_rewarded_at = null;
        $customer->save();
    }
}
