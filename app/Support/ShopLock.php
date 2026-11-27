<?php

namespace App\Support;

use App\Models\Shop;

/**
 * The one lock-order rule for every request that changes the shop's cash_balance / bank_balance (QA Phase 3, D10):
 *
 *     shop row  ->  customer / supplier row  ->  product / size rows
 *
 * PosController, ReturnController, SalesController (void), loans, salary, partners and rentals already take the shop row
 * first. Screens that did NOT (expenses, purchases, customer collections, paid stock-in) inserted their own row first —
 * MariaDB takes a SHARED lock on the parent `shops` row for the foreign key — and only then ran
 * `UPDATE shops SET cash_balance = ...`, which needs an EXCLUSIVE lock. Two such requests each hold the shared lock and
 * wait for the other to let go: a deadlock, one request dies with "server temporary problem", and (worse) the same
 * mismatch of order made a checkout and an expense deadlock each other.
 *
 * Call acquire() as the FIRST statement inside the DB::transaction() of any request that will move cash or bank money,
 * before it locks a customer, supplier or product. The lock lasts until the transaction ends.
 */
final class ShopLock
{
    public static function acquire(): ?Shop
    {
        return Shop::whereKey(Tenancy::id())->lockForUpdate()->first();
    }

    /**
     * For flows where money only moves for some payment methods (a credit purchase, or a stock-in with no supplier or
     * no payment, never touches the shop row — locking there would only serialise harmless work).
     */
    public static function acquireIfPaid(?string $method, bool $hasPayee = true): ?Shop
    {
        return $hasPayee && in_array($method, ['cash', 'bank'], true) ? self::acquire() : null;
    }
}
