# Bug Report — Consolidated

Part of the full technical audit (section 16, deliverable #8). Every confirmed issue found across `TENANT_CUSTOMIZATION_ISOLATION_AUDIT.md`, `FEATURE_AUDIT.md`, `INVENTORY_STOCK_AUDIT.md`, `DATABASE_AUDIT.md`, `SECURITY_AUDIT.md`, `SCALABILITY_AUDIT.md`/`PERFORMANCE_AUDIT.md`, severity-classified per section 15 (P0=financial/data corruption or security breach, P1=critical business function broken, P2=significant, P3=minor). Each issue's own source document has the full evidence trail; this is the index.

**P0: 0. P1: 1 (fixed). P2: 2 (1 fixed, 1 recommended follow-up). P3: 5 (not fixed — none are urgent; logged for the record).**

---

### BUG-001 — Warehouse/head-office checkout guard bypassable via payment-gateway webhook
- **Module**: POS checkout / tenant customization isolation
- **Severity**: P2
- **Found in**: `TENANT_CUSTOMIZATION_ISOLATION_AUDIT.md` (2026-11-20, earlier this session)
- **Description**: the `is_warehouse`/`is_head_office` checkout block lived only in `PosController::checkout()`; the payment-gateway webhook and two other internal callers invoked `performCheckout()` directly, skipping it entirely.
- **Reproduction**: call `PosController::performCheckout()` directly (bypassing the HTTP route) against a warehouse-flagged shop — used to succeed.
- **Expected**: 422, no sale created, regardless of which code path reaches `performCheckout()`.
- **Actual (before fix)**: succeeded via the webhook path.
- **Root cause**: guard duplicated at one caller instead of the shared choke point.
- **Fix**: moved the guard into `performCheckout()` itself, right after the shop is locked. Commit `58ec3a30`.
- **Status**: **FIXED, deployed, verified live** (production HEAD matches this commit).
- **Test evidence**: `tests/Feature/CheckoutTransactionTest.php::test_performcheckout_itself_blocks_a_warehouse_regardless_of_caller` (calls the static method directly).

---

### BUG-002 — Purchase of a variant product ignores weighted_average_costing (confirmed live impact on SEAMAN)
- **Module**: Purchases / inventory costing
- **Severity**: P1 — real, ongoing financial-reporting corruption on a live paying shop (not data loss, but every affected restock silently records the wrong cost, which skews profit/margin figures until the next restock overwrites it again).
- **Found in**: `INVENTORY_STOCK_AUDIT.md` (fork audit, 2026-11-20)
- **Description**: `PurchaseController`'s variant-purchase branch always plain-overwrote `ProductVariant.cost` with the new purchase's cost, regardless of the shop's `weighted_average_costing` feature flag — while the manual Stock-page stock-in route (`ProductVariantController::stockIn()`) correctly blends old and new cost into a moving average when that flag is on. The two routes disagreed about the same shop's own costing policy.
- **Reproduction** (exact numbers from the audit): a variant at stock=1, cost=1600, on a shop with `weighted_average_costing` on. Restock 5 units @ cost 1750.
  - Via Stock-page stock-in: correctly blends to **1725** `((1×1600 + 5×1750) / 6)`.
  - Via Purchase (before fix): recorded **1750** (plain overwrite) — wrong.
- **Expected**: both routes produce 1725.
- **Actual (before fix)**: Purchase route produced 1750.
- **Root cause**: `PurchaseController.php`'s variant branch had its own, independent (and incorrect) cost-write, with a comment incorrectly claiming it matched `ProductVariantController::stockIn()` "exactly" — it never checked `Shop::usesWeightedAverageCosting()` at all.
- **Fix**: rewrote the branch to mirror `ProductVariantController::stockIn()`'s exact cost-resolution logic (source_lines → per-lot blended cost via `ProductSource::upsertLine()`; otherwise → weighted-average-or-overwrite based on the feature flag), including the previously-missing product-cost mirror sync for a line-tracked shop.
- **Status**: **FIXED locally this session, tested, pending commit+deploy** (see `FIX_LOG.md`).
- **Test evidence**: `tests/Feature/BrandTest.php::test_a_purchase_of_a_variant_respects_weighted_average_costing_the_same_way_stockin_does` — both the with-feature (1725) and without-feature/Baby-Park-world (550, unchanged default behavior) cases pass.

---

### BUG-003 — Nightly database backup silently stopped running for 6 days after the cloud migration
- **Module**: Infrastructure / disaster recovery
- **Severity**: P2 (operational, not a code defect)
- **Found in**: `SCALABILITY_AUDIT.md` section E (this audit, live log/disk inspection)
- **Description**: `zaylotix:backup` (scheduled `dailyAt('02:00')`) had no successful run between 2026-11-14 (the Cloud Professional migration cutover) and 2026-11-20 — most likely because cron wasn't fully active on the new server for part of that window.
- **Reproduction**: `ls storage/app/private/backups/` on production showed the newest file dated 2026-11-14, six days stale as of 2026-11-20.
- **Expected**: a fresh `.sql` backup every night.
- **Actual (before fix)**: none since the migration.
- **Root cause**: cron activation gap on the newly-migrated server (not a code bug — the command itself runs correctly on demand, confirmed below).
- **Fix**: ran `php artisan zaylotix:backup` manually to close the immediate gap (`zaylotix-pos-2026-11-20_114234.sql` confirmed on disk).
- **Status**: **Immediate gap closed. Needs a human check tomorrow** — confirm a `zaylotix-pos-2026-11-21_02....sql` file appears on its own; if not, cron still isn't reliably firing this task.

---

### BUG-004 — `composer audit`: 33 dependency advisories, including 1 critical (triaged, not exploitable in this app's config)
- **Module**: Dependencies
- **Severity**: P2
- **Found in**: `SECURITY_AUDIT.md`
- **Description**: outdated packages carry known CVEs, most notably Laravel's TokenGuard auth-bypass advisory and high-severity Guzzle/Excel/Symfony issues.
- **Individually verified not currently exploitable**: no TokenGuard guard configured anywhere in `config/auth.php`; Laravel Excel is only ever called via `download()` (streams to the response), never the vulnerable `store()`-with-caller-supplied-path pattern.
- **Status**: NOT FIXED this pass — `composer update` across 11 packages is its own risk-laden change (needs a dedicated regression pass, not something to fold into an audit silently). **Recommended as a standalone follow-up task**, not actioned here.

---

### BUG-005 — `ExportController::supplierRows()` runs one SUM query per supplier instead of one grouped query
- **Module**: Exports / performance
- **Severity**: P3
- **Found in**: `DATABASE_AUDIT.md`
- **Description**: confirmed real N+1 via code read — correctness is unaffected, purely an avoidable extra-queries-per-export cost. The grouped-query pattern already exists elsewhere in the same file (`companyBranchBreakdown()`) to copy.
- **Status**: NOT FIXED — logged for a future minor cleanup pass.

---

### BUG-006 — Dormant FK-cascade inconsistency on `payments`/`installment_plans`/`rentals`.`customer_id`
- **Module**: Database schema
- **Severity**: none today (currently unreachable) — would become real the day a customer-delete feature is ever built.
- **Found in**: `DATABASE_AUDIT.md`
- **Description**: these three tables use `cascadeOnDelete()` on `customer_id` while `sales.customer_id` correctly uses `nullOnDelete()` (preserve transaction history). Confirmed via full-codebase grep that no customer-delete endpoint exists anywhere today, so this cannot currently fire.
- **Status**: NOT FIXED — flagged so a future customer-delete feature doesn't silently wipe payment/installment/rental history.

---

### BUG-007 — No self-service "forgot password" flow (design, not a defect)
- **Module**: Authentication
- **Severity**: POTENTIAL RISK, not a bug
- **Found in**: `FEATURE_AUDIT.md`
- **Description**: only the platform admin can reset a shop owner's login credentials — a single point of support dependency if an owner is ever locked out outside business hours.
- **Status**: not fixed (pre-existing, intentional design) — flagged for Khaled's awareness only.

---

### BUG-008/009 — Two minor security hardening items
- **Module**: Security config / file uploads
- **Severity**: P3 each
- **Found in**: `SECURITY_AUDIT.md`
- **Description**: (1) `SESSION_SECURE_COOKIE` relies on HTTPS auto-detection rather than being explicitly set to `true`; (2) Article-photo and ProductVariant-photo upload endpoints use the bare `image` validation rule (allows svg/bmp/gif) instead of an explicit `mimes:` whitelist like the other 4 upload endpoints. Neither is currently exploitable (no uploaded content is ever rendered via `v-html`).
- **Status**: NOT FIXED — minor hardening, logged for a future small pass.

---

### BUG-010 — `ExpenseController` has no dedicated automated test
- **Module**: Expenses
- **Severity**: NOT TESTED (no defect found on manual read — tenant-scoped correctly, transaction-wrapped correctly)
- **Found in**: `FEATURE_AUDIT.md`
- **Status**: not fixed — recommended a follow-up `ExpenseTest.php`.

---

### BUG-011 — Multi-item (2+ products) exchange has no automated test
- **Module**: Returns/Exchange
- **Severity**: P3 (NOT TESTED — code supports it, no regression test exists)
- **Found in**: `INVENTORY_STOCK_AUDIT.md`
- **Status**: not fixed — recommended follow-up test.

---

### BUG-012 — `DeadStockController`'s `stock_value` field name is ambiguous
- **Module**: Reports (dead-stock clearance)
- **Severity**: P3 (naming/documentation gap, not a math error)
- **Found in**: `INVENTORY_STOCK_AUDIT.md`
- **Description**: that field means `stock × price` (retail value) there, while every other place using the same field name means `stock × cost` (asset value).
- **Status**: not fixed — flagged so no future reader conflates the two.
