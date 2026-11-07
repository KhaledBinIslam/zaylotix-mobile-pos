<?php

namespace App\Support;

use App\Models\Shop;
use App\Models\Supplier;

/**
 * Khaled live: "koto tk pay korche add korte parbe koto due ache show
 * hobe" — every stock-in screen that picks a supplier (the simple
 * stock-in sheet, the opening-stock grid, the Part 1 "stock-in from a
 * supplier" sheet) recorded WHO the stock came from, but none of them
 * ever touched what's actually owed for it — only the separate
 * Purchases module (PurchaseController::applyEffects()) did. A shop
 * that never uses Purchases had no due/payable tracking at all from its
 * everyday stock-in screens.
 *
 * One shared choke point, same cash/bank/credit split and the same
 * effect PurchaseController already has, so every stock-in screen agrees
 * with Purchases on what "paid" and "on credit" mean — no screen can
 * drift from another on this again.
 */
class SupplierPayable
{
    public const METHODS = ['cash', 'bank', 'credit'];

    /**
     * $method is nullable/absent entirely for a stock-in with no supplier
     * picked (nothing owed to anyone) or for a shop not using this at
     * all yet — a no-op, zero behavior change for either case.
     */
    public static function apply(?int $supplierId, ?string $method, float $amount): void
    {
        if (! $supplierId || ! $method || $amount <= 0) {
            return;
        }

        if ($method === 'credit') {
            Supplier::whereKey($supplierId)->increment('payable', $amount);

            return;
        }

        // deliberately allowed to go negative rather than clamped to 0 —
        // same reasoning as PurchaseController/ExpenseController: a floor
        // would hide a real shortfall instead of surfacing it
        $field = $method === 'bank' ? 'bank_balance' : 'cash_balance';
        Shop::whereKey(Tenancy::id())->decrement($field, $amount);
    }
}
