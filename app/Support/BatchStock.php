<?php

namespace App\Support;

use App\Models\Product;
use App\Models\ProductBatch;

/**
 * FEFO (first-expiry-first-out) layer on top of a product's plain stock
 * count. Product.stock (validated/decremented in PosController) remains the
 * single source of truth for "how much is on hand"; this tracks *which*
 * batch the units conceptually came from, for expiry visibility, alerts,
 * and precise restoration if the sale is later voided.
 *
 * One deliberate exception to "never blocks": deduct() refuses to draw from
 * an expired batch at all — selling already-expired medicine is a real
 * regulatory/health risk, not just a bookkeeping nicety, so PosController
 * calls sellableQty() *before* the sale to reject it outright rather than
 * silently under-covering the allocation the way an untracked-stock
 * shortfall is allowed to.
 */
class BatchStock
{
    /**
     * How much of a product's total stock is actually sellable right now —
     * total stock minus whatever sits in an expired-but-still-quantified
     * batch. Untracked stock (never given a batch — e.g. it existed before
     * batch tracking was turned on) has no expiry info at all, so it's
     * always counted as sellable; only stock explicitly tracked into an
     * expired batch is excluded. This single formula handles every mix of
     * tracked/untracked stock without needing to separately reconcile them.
     */
    public static function sellableQty(Product $product): float
    {
        $expiredQty = (float) ProductBatch::where('product_id', $product->id)
            ->available()->where(fn ($q) => $q->whereNotNull('expiry_date')->whereDate('expiry_date', '<', now()->toDateString()))
            ->sum('qty');

        return max(0, (float) $product->stock - $expiredQty);
    }

    public static function receive(Product $product, int $qty, ?string $batchNo, ?string $expiryDate, ?float $cost): void
    {
        if ($qty <= 0 || ($batchNo === null && $expiryDate === null)) {
            return;
        }

        ProductBatch::create([
            'shop_id' => $product->shop_id,
            'product_id' => $product->id,
            'batch_no' => $batchNo,
            'expiry_date' => $expiryDate,
            'qty' => $qty,
            'cost' => $cost ?? $product->cost,
        ]);
    }

    /**
     * Deducts up to $qty from the soonest-expiring *non-expired* available
     * batches. If tracked (non-expired) batches don't cover the full amount
     * (common right after turning batch tracking on — older stock was never
     * batched, or PosController's sellableQty() pre-check already confirmed
     * enough untracked stock exists to make up the rest), it simply deducts
     * what it can and stops. An expired batch is never drawn from here —
     * that's what actually keeps expired medicine off a receipt, not just a
     * cosmetic ordering preference.
     *
     * @return array<int, array{batch_id: int, qty: int}> exactly what was taken from where — snapshot this
     *                                                     onto the sale_item so a later void can restore it precisely (see restore()).
     */
    public static function deduct(Product $product, int $qty): array
    {
        $remaining = $qty;
        $allocations = [];

        $batches = ProductBatch::where('product_id', $product->id)
            ->available()->notExpired()->fefoOrder()->lockForUpdate()->get();

        foreach ($batches as $batch) {
            if ($remaining <= 0) {
                break;
            }

            $take = min($batch->qty, $remaining);
            $batch->decrement('qty', $take);
            $remaining -= $take;
            $allocations[] = ['batch_id' => $batch->id, 'qty' => $take];
        }

        return $allocations;
    }

    /**
     * Undoes a deduct() — puts each recorded qty back into the batch it was
     * taken from, given the allocation snapshot stored on the sale_item.
     * Silently skips a batch that no longer exists rather than throwing —
     * voiding a very old sale must never fail just because batch bookkeeping
     * has since moved on.
     *
     * @param  array<int, array{batch_id: int, qty: int}>|null  $allocations
     */
    public static function restore(?array $allocations): void
    {
        foreach ($allocations ?? [] as $allocation) {
            ProductBatch::whereKey($allocation['batch_id'])->lockForUpdate()->increment('qty', $allocation['qty']);
        }
    }
}
