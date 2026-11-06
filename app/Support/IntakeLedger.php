<?php

namespace App\Support;

use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\StockIntake;
use Illuminate\Support\Facades\Auth;

/**
 * The one writer of stock_intakes rows — "কত টাকার মাল উঠেছে" in taka,
 * recorded at the moment the stock moves, inside the SAME transaction.
 * Deliberately not wrapped in a try/catch: a report whose whole point is
 * "ammount jate vul na dekhai" must refuse the intake rather than let it
 * slip through unrecorded.
 *
 * The unit cost falls back the same way every price lookup in this app
 * does: the variant's own cost first, then the article's, then zero — a
 * zero-cost row is still a row, so the QUANTITY story stays complete
 * even where the owner never entered a cost.
 */
class IntakeLedger
{
    public static function record(Product $product, ?ProductVariant $variant, float $qty, ?float $unitCost, string $source, ?int $supplierId = null, ?int $sourceId = null): void
    {
        if ($qty <= 0) {
            return; // nothing came in — a zero-qty grid cell, not an intake
        }

        $cost = $unitCost ?? ($variant?->cost !== null ? (float) $variant->cost : null) ?? (float) ($product->cost ?? 0);

        StockIntake::create([
            'shop_id' => $product->shop_id,
            'product_id' => $product->id,
            'product_variant_id' => $variant?->id,
            'user_id' => Auth::id(),
            // FROM WHOM, alongside what and at which cost — the same Power X
            // shoe really does arrive from two suppliers at two prices
            'supplier_id' => $supplierId,
            // Part 1 (source_lines) — WHICH exact lot, when the shop tracks
            // them; null for every shop/stock-in that doesn't
            'product_source_id' => $sourceId,
            'qty' => $qty,
            'unit_cost' => round($cost, 2),
            'total_cost' => round($qty * $cost, 2),
            'source' => $source,
        ]);
    }
}
