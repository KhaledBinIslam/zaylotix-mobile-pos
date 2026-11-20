# Database Integrity Audit

Scope: Section 4 ("Database Integrity Audit") of the full technical audit
requested 2026-11-20, plus the pure data-integrity angle of Section 5 that
`TENANT_CUSTOMIZATION_ISOLATION_AUDIT.md` (read first, not repeated here)
didn't already cover from its isolation lens. Investigated by direct
inspection of all 215 migration files, every money-moving controller, and a
targeted run of the existing automated suite. No application code was
changed; no production system was touched.

Labels used: **VERIFIED PASS** · **VERIFIED FAIL** · **NOT TESTED** ·
**BLOCKED BY MISSING ACCESS** · **POTENTIAL RISK** — same legend as the
tenant-isolation audit.

---

## 1. Foreign key integrity and indexing

215 migration files; 104 use `foreignId()`/`foreign()`. Every genuine
relationship column is paired with `->constrained()` (confirmed by grepping
every `foreignId(` call site and checking the following line) — the one
column with no FK constraint, `sessions.user_id`
(`2024_01_01_000004_create_users_table.php:36`), is Laravel's own stock
session-table shape (no `php artisan session:table` scaffold constrains it
either — ephemeral, high-churn, informational-only). **VERIFIED PASS.**

Deletion rules were spot-checked against what they protect:

- `sale_items.product_id` → `nullOnDelete()`, `sales.customer_id` →
  `nullOnDelete()` — a deleted product/customer never destroys historical
  sales records, it just nulls the reference. Correct for a financial
  ledger. **VERIFIED PASS.**
- Every `shop_id` FK across all 215 migrations uses `cascadeOnDelete()` —
  correct for a true tenant-owned row (deleting a shop should remove
  everything scoped to it, not orphan it).
- `shop_id` itself needs no separate `->index()` call to be indexed: MySQL/
  InnoDB requires an index to create a FK constraint at all, so every
  `constrained()` call already produces one as a side effect. A composite
  index opportunity (e.g. `shop_id + date` for report range-scans) is a
  performance question, out of this section's scope — see
  `PERFORMANCE_AUDIT.md`.

### Finding — inconsistent deletion rule on `customer_id` across 3 tables vs. `sales` — POTENTIAL RISK, currently dormant

`payments.customer_id`, `installment_plans.customer_id`, and
`rentals.customer_id` are all `cascadeOnDelete()` — deleting a `Customer`
would silently destroy their payment ledger, installment schedule, and
rental history, which is the opposite of `sales.customer_id`'s
`nullOnDelete()` precedent (preserve financial history, just drop the
reference).

**Why this is not exploitable today**: grepped the entire `app/` tree and
`routes/web.php` for any `Customer`-deleting code path — `Customer` model
does `use SoftDeletes` (`app/Models/Customer.php:23`), but there is no
`destroy()` method on `CustomerController`, no delete route for customers
anywhere in `routes/web.php`, and no other call site that invokes
`->delete()` on a `Customer` instance. **A customer can never actually be
deleted in this application today** — the cascade rule is schema-correct
syntax sitting on a dead code path. **VERIFIED PASS for current behavior**
(nothing is broken because nothing can trigger it), but flagged as
**POTENTIAL RISK** for the day a "delete this customer" feature gets built
(a GDPR-style request, an admin cleanup tool) without someone first
checking this file — it would silently wipe real due/payment/rental
history instead of preserving it. **Recommended fix** (not applied, audit
is read-only): align those 3 FKs to `nullOnDelete()` to match `sales`'
already-correct precedent, or block deletion of a customer with any
non-zero due/outstanding balance, whenever customer deletion is ever built.

## 2. Duplicate/orphan record risk

Beyond the dormant cascade issue above, no other orphan-risk pattern was
found: every other transactional child table (`sale_items`, `purchase`
lines, `rental_items`, `rental_returns`, `installment_schedule_items`)
either cascades with its own parent transaction row (correct — a line item
has no meaning without its parent) or nulls a secondary reference
(`product_id`, `product_variant_id`, `supplier_id`, `brand_id`) rather than
cascading from it. **VERIFIED PASS.**

## 3. Decimal precision

Zero `$table->float(...)` or `->double(...)` calls exist anywhere in
`database/migrations/` (grepped the full 215-file set) — every monetary
column is `decimal`. Precision is deliberately tiered, not accidental:
`(12,2)` for per-transaction amounts (sale/purchase/payment totals —
ceiling ≈9.9 billion, no realistic overflow risk for this business size),
`(14,2)` for cumulative balances that compound over a shop's lifetime
(`cash_balance`, `bank_balance`, `capital`, `total_spent`, cash
transactions, loans, partner equity), `(10,2)`/`(8,2)` for smaller-scoped
figures (monthly fees, loyalty rate multipliers), `(5,2)` for percentages
(VAT rate, turnover rate, ownership %, discount %, correctly capped under
1000%). 138 model-level `decimal:` casts confirmed consistent with their
migrations. **VERIFIED PASS.**

## 4. N+1 query patterns

Swept every `Reports.php`, `ExportController.php`,
`CompanyDashboardController.php`, and `StockMovementController.php` method
that runs a `->get()` followed by a `foreach`. Nearly every one already
eager-loads what it touches (`->with('customer')`, `->with('product:id,name')`,
etc.) — confirmed deliberate, not accidental, given how consistently it's
applied.

### Finding — real N+1 in `ExportController::supplierRows()` — P3

`app/Http/Controllers/App/ExportController.php:168-174`:

```php
foreach (Supplier::all() as $s) {
    $lifetime = Purchase::where('supplier_id', $s->id)->sum('amount');
    ...
}
```

This runs one extra `SUM()` query per supplier row — a shop with 50
suppliers makes 51 queries instead of 2 on every Supplier Report export
click. **VERIFIED FAIL** (confirmed by reading the code; the pattern is
unambiguous — not a hypothetical). Not a correctness bug (each sum is
independently right) and not currently painful (supplier counts per shop
are small today), so **P3** per Section 15's own definitions ("minor issue
or optimization opportunity"), not higher. **Recommended fix** (not
applied): replace the per-row query with one grouped query up front —
`Purchase::selectRaw('supplier_id, SUM(amount) as total')->groupBy('supplier_id')->get()->keyBy('supplier_id')`
— then look up each supplier's lifetime total from that collection inside
the loop, exactly the pattern `Reports::companyBranchBreakdown()` already
uses correctly a few files away.

Every other `foreach` checked (`customerRows()`, `salesByType()`, the
`StockMovementController` transfer loop) either touches only scalar
columns already on the loaded row, or reads from a pre-fetched `Collection`
lookup (`$shopNames->get($id)`, a Collection method, not a query) — zero
further N+1 risk found. **VERIFIED PASS** for the rest.

## 5. Transaction and locking discipline

Counted `DB::transaction`/`lockForUpdate` occurrences in every money- or
stock-moving controller named in this section's brief:

| Controller | Occurrences |
|---|---|
| PosController | 12 |
| ReturnController | 17 |
| PurchaseController | 6 |
| PaymentController | 12 |
| StockTransferController | 15 |
| SupplierPaymentController | 3 |
| RentalController | 11 |
| InstallmentController | 3 |
| GatewayWebhookController | 3 |

All nine real App-side money/stock controllers show substantial, consistent
use — matches the discipline already confirmed throughout this engagement's
own fix history (every `performCheckout()`/`processReturn()`/payment-
collection path locks its row before reading or writing it).

**One exception, checked and judged acceptable**: `Admin\SubscriptionController::store()`
has zero `DB::transaction`/`lockForUpdate` calls — it writes a
`SubscriptionPayment` row then does a plain `Shop::whereKey(...)->update([...])`.
This is a human admin manually recording a renewal (not a customer-facing,
raceable endpoint), and the update itself **overwrites** fields
(`subscription_expiry`, `plan`, `status`) rather than incrementing/
accumulating a value — even a theoretical double-submit from the same admin
produces "last click wins," not corrupted or double-counted money.
**VERIFIED PASS** (checked, not a real gap) rather than a finding — the
absence of locking here is proportionate to the actual risk, not an
oversight to match PosController's.

## 6. Concurrency

`tests/Feature/RealWorldWorkflowAuditTest.php::test_two_cashiers_selling_at_nearly_the_same_time_never_lose_a_stock_update`
exercises two sequential `performCheckout()` calls against the same shop's
same product and asserts neither's stock/cash update is lost — the test's
own comment is honest about its limit: *"not truly simultaneous threads
(PHPUnit is single-threaded)... this is exactly the code path two real
concurrent requests take."* This verifies the lock-respecting code path
produces the correct final state, but does **NOT** exercise genuine
multi-connection MySQL-level lock contention (two real, separate DB
connections blocking on the same `FOR UPDATE` row at the same instant) —
that needs a multi-process test harness this environment doesn't have.
Ran this file plus three other concurrency-adjacent suites
(`InstallmentPlanTest`, `ShopOperationsTest`, `RestaurantOfflineSyncTest`)
live:

```
Tests:    44 passed (285 assertions)
Duration: 21.83s
```

**VERIFIED PASS** for sequential-order correctness under the real lock code
path. **NOT TESTED** for true concurrent-connection lock contention — an
inherent PHPUnit/single-process limitation, not a code gap; the code itself
(`lockForUpdate()` inside `DB::transaction()`) is what MySQL actually
enforces at the database level regardless of what PHPUnit can simulate.

---

## Summary

| # | Finding | Severity | Status |
|---|---|---|---|
| 1 | `payments`/`installment_plans`/`rentals`.`customer_id` cascade-deletes instead of null-on-delete, inconsistent with `sales` | POTENTIAL RISK (dormant — no delete path exists) | Not fixed (read-only audit) |
| 2 | `ExportController::supplierRows()` runs one query per supplier (N+1) | P3 | Not fixed (read-only audit) |

**P0 count: 0. P1 count: 0. P2 count: 0. P3 count: 1** (plus the one
dormant architectural note above, which carries no severity since nothing
can trigger it today).

**Overall verdict**: no data-corrupting bug, no missing constraint, no
float-precision risk, and no real transaction/locking gap was found in any
money- or stock-moving path. The two items above are a minor performance
inefficiency and a schema inconsistency that is currently unreachable —
neither threatens financial accuracy or data integrity today.
