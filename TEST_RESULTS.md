# Test Results

Part of the full technical audit (section 16, deliverable #9).

## Automated test suite — full run, end of this audit (2026-11-20)

| Suite | Result |
|---|---|
| PHP (Pest/PHPUnit, `php artisan test`) | **1774 / 1774 passed** (8215 assertions), 608.7s |
| JavaScript (Vitest, `npx vitest run`) | **343 / 343 passed**, 37 test files |

**Failed tests: 0.** Grew from 1766 PHP / 343 JS at the start of this audit continuation to 1774 PHP (8 new: 7 for the `billing_follows_parent` combined-subscription feature shipped just before this audit resumed, 1 for `FIX-002`'s purchase-costing bug) / 343 JS (unchanged — no frontend code touched this pass).

## Coverage by audit section (see each document for full detail)

- **`FEATURE_AUDIT.md`**: 35 modules cross-referenced against real route/controller/test evidence — 32 VERIFIED PASS, 1 NOT TESTED (`ExpenseController`, no bug found on inspection), 1 NOT BUILT (self-service password reset, by design), 1 design clarification (no tiered packages — per-feature flags instead, correctly tested).
- **`INVENTORY_STOCK_AUDIT.md`**: 87 tests run fresh across `CheckoutTransactionTest`, `ReturnExchangeTest`, `WholesaleTiersTest`, `DiscountPriceCheckoutTest`, `DeadStockTest` — all passed. One real P1 bug found (now fixed, `FIX-002`), two P3 gaps logged.
- **`DATABASE_AUDIT.md`**: 44 concurrency-adjacent tests run live, all passed (with the honest caveat that PHPUnit can't exercise true multi-connection MySQL lock contention — only the correct sequential code path). Zero float/double money columns across 138 decimal casts; all money/stock controllers show strong transaction+lock discipline.
- **`SECURITY_AUDIT.md`**: 11 categories checked via safe code/config inspection (no live exploitation attempted). Zero P0/P1. One P2 (dependency CVEs, triaged — the two most severe confirmed not exploitable in this app's actual config).
- **`PERFORMANCE_AUDIT.md`**: 5 real timing/query-count measurements taken against production's largest real dataset (Global Touch, 6 months of data) — all sub-20ms wall time, single-digit query counts, no N+1 observed.
- **`TENANT_CUSTOMIZATION_ISOLATION_AUDIT.md`** (completed earlier, referenced not repeated): 9 directly-relevant test files, 72/72 passing at the time; one P2 found and fixed (`FIX-001`).

## Stress / load testing (section 10)

**NOT TESTED.** No k6/JMeter run was performed against any concurrency profile (10 through 1,000 users). Full reasoning in `SCALABILITY_AUDIT.md` — single production server, no staging environment, no isolated environment to safely absorb a failed test. This is a deliberate scope boundary stated to Khaled directly before this audit resumed, not an oversight.

## Evidence classification used throughout this audit

Per the master prompt's own requirement, every claim across all audit documents is one of: **VERIFIED PASS** (a real test or live measurement backs it), **VERIFIED FAIL** (a real bug, logged in `BUG_REPORT.md`), **NOT TESTED** (no automated coverage exists, no bug found on inspection either), **BLOCKED BY MISSING ACCESS** (would need infrastructure that doesn't exist, e.g. a staging environment), or **POTENTIAL RISK** (a design trade-off worth knowing about, not a confirmed defect). No feature or check in any of these documents is marked passed without a specific citation.
