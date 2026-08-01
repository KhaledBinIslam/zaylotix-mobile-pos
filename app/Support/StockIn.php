<?php

namespace App\Support;

use App\Models\Product;

/**
 * Adds qty to a product's stock and, if a new cost is given, folds it into
 * Product.cost as a weighted average rather than overwriting it — replacing
 * the cost outright would silently reprice every unit already on the shelf
 * even though only the newly received units cost that much. Shared by
 * ProductController::stockIn and PurchaseController (when a purchase also
 * names a product/qty), so the two can never compute this differently.
 *
 * Caller must already hold the lock (Product::whereKey($id)->lockForUpdate()->first())
 * and be inside a DB transaction — this just does the arithmetic and saves.
 */
class StockIn
{
    public static function apply(Product $lockedProduct, float $qty, ?float $cost): void
    {
        $newCost = $lockedProduct->cost;
        if ($cost !== null) {
            $existingValue = $lockedProduct->stock * $lockedProduct->cost;
            $incomingValue = $qty * $cost;
            $newCost = ($existingValue + $incomingValue) / ($lockedProduct->stock + $qty);
        }

        $lockedProduct->update([
            'stock' => $lockedProduct->stock + $qty,
            'cost' => round($newCost, 2),
        ]);
    }
}
