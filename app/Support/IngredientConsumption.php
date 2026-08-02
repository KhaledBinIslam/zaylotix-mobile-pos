<?php

namespace App\Support;

use App\Models\Product;

/**
 * Best-effort ingredient bookkeeping layered on top of a sale — mirrors the
 * batch/serial-tracking pattern established elsewhere (see BatchStock/
 * SerialStock): products.stock stays the single authoritative number every
 * sale/checkout already decrements; a product with no recipe configured
 * simply isn't touched here at all. Never blocks a sale, and — like
 * cash_balance/supplier.payable elsewhere in this codebase — deliberately
 * allowed to go negative if oversold, rather than silently clamped, so a
 * shortfall stays visible instead of being hidden.
 */
class IngredientConsumption
{
    public static function apply(Product $product, float $qty): void
    {
        $recipes = $product->recipes()->with('ingredient')->get();

        foreach ($recipes as $recipe) {
            $recipe->ingredient?->decrement('stock', $recipe->qty_per_unit * $qty);
        }
    }
}
