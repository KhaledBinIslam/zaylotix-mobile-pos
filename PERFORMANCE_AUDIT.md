# Performance & Database Optimization Audit (Section 7)

Part of the full technical audit (section 16, deliverable #6). Companion to `SCALABILITY_AUDIT.md`, which explains why no concurrent-load figures (P50/P95/P99 under traffic, throughput ceiling) appear here — there is no staging environment and no load-testing tool, and this is the single server real paying customers use right now. Everything in this document is either a real measurement taken safely (no load imposed, read-only) or an honest NOT TESTED.

## A. Real measurements (2026-11-20, production, Global Touch — the platform's largest single dataset: 6 months of realistic demo data, 2 branches + a warehouse)

Measured by calling the actual business-logic methods directly (no HTTP layer, no network, no browser rendering) with a live `DB::listen()` query counter — this isolates "is the database/business-logic layer itself efficient" from web-server/network overhead, which is a separate, untested variable.

| Operation | Wall time | Query count | DB time |
|---|---|---|---|
| `Reports::rangeStats()` — dashboard summary, 6-month range | 12.7ms | 8 | 3.5ms |
| `Reports::companyBranchBreakdown()` — Business Dashboard, all branches, 6-month range | 18.1ms | 9 | 5.6ms |
| `Reports::topProducts()` — 6-month top-20 | 12.2ms | 2 | 3.0ms |
| `StockMovementController::rowsFor()` — full stock-movement feed, unfiltered | 16.7ms | 9 | 4.8ms |
| `Sale` count+aggregate across a 2-branch group, 6-month range | 0.5ms | 1 | 0.4ms |

**Verdict: VERIFIED PASS.** No query counts in the N+1 range (all single-digit for genuinely multi-entity aggregations spanning a 6-month window and multiple branches), all wall times comfortably sub-20ms at the business-logic layer. This matches `DATABASE_AUDIT.md`'s independent finding (via static code inspection) that the money/report-generating controllers use grouped/eager-loaded queries rather than per-row loops, with the one confirmed exception (`ExportController::supplierRows()`, logged there as a P3).

**What this does NOT measure**: full end-to-end HTTP response time (Nginx + PHP-FPM bootstrap + Inertia serialization + network + browser render), behavior under concurrent load, or a smaller/more typical shop's numbers (Global Touch was deliberately chosen as the heaviest real dataset — a typical single-branch shop like SEAMAN would be equal or faster, not worse).

## B. Server-level resource baseline (read-only, no load imposed)

| Metric | Value |
|---|---|
| PHP `memory_limit` | 6144M |
| OPcache | Enabled |
| Disk | 71% used (21T/15T), shared-node figure |

Full detail and the one real finding from this pass (6-day gap in nightly backups, now closed) is in `SCALABILITY_AUDIT.md` section E to avoid duplicating it here.

## C. Cache usage review

- **Cache driver**: `database` (MySQL-backed `cache` table), confirmed live via production log evidence (`select * from cache where key in (...)` queries seen in `storage/logs/laravel.log`). Not Redis/Memcached — slower than an in-memory store under heavy concurrent cache traffic, but correctly centralized (see `SCALABILITY_AUDIT.md` section A) and today's measured query volumes (above) don't show cache-layer pressure as a bottleneck.
- **Tenant-scoped cache keys**: already verified clean in `TENANT_CUSTOMIZATION_ISOLATION_AUDIT.md` (2026-11-20) — not re-litigated here.
- **Cache invalidation**: no dedicated stale-cache regression test found; not flagged as a bug (no evidence of a real staleness incident), just NOT TESTED as its own scenario.

## D. N+1 / query-efficiency code review

Delegated to, and already covered by, `DATABASE_AUDIT.md` (section 4's scope explicitly includes N+1 pattern scanning) — not duplicated here to avoid two documents disagreeing if one gets updated later. Its one finding (`ExportController::supplierRows()`, P3) applies to this section too.

## E. What's genuinely NOT TESTED in this section, and why

- **P50/P95/P99 under real concurrent traffic** — needs either an APM tool watching real production traffic over time (none installed) or synthetic load testing (explicitly declined — see `SCALABILITY_AUDIT.md`).
- **PHP-FPM worker utilization / queue processing time under load** — same reason.
- **API response times** — this app has no separate public API surface beyond the Inertia-driven app routes and the few documented public endpoints (catalog, rating) — not a distinct measurable category here.
- **Large report/export generation time at real scale** (e.g. a shop with years of data, not 6 months) — NOT TESTED; the heaviest dataset actually available on the platform today was used instead (see section A).

## Section 17 scores contributed by this document

- **Performance**: strong on the one axis actually measurable here (business-logic/query efficiency — no N+1, low query counts, sub-20ms). Unscored on the concurrent-load axis — genuinely untested, not assumed-good.
