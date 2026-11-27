# QA REPORT — Zaylotix ERP/POS

Living document. **Only tests that were actually executed are marked as passed.** Anything not run is listed as *NOT EXECUTED*.
No production data was modified by any test. Production was touched read-only (counts, sizes, configuration names) and, for deploys, with a database backup first. No secrets, passwords or customer details are recorded here.

Last updated: 2026-11-27 · Production commit: `1a6a0fb9` · Newest commit (**not deployed**, awaiting the owner's explicit go): `d7705fba` (ZV-sticker resolver recursion fix) · Phase 3 test files are in the working tree, see §9

---

## 0. Status at a glance

| Phase | State |
|---|---|
| 1. Codebase and architecture audit | **Done** (this document, §1–§3) |
| 2. Test planning / roadmap | **Done** (§6) |
| 3. Critical functional testing | **Executed 2026-11-27 — results, bug register and open approvals in §9.** 13 defects/decisions registered, none fixed yet (fixes to business-critical logic wait for the owner's approval) |
| 4. Database and data integrity | Partly executed (stock identity, supplier valuation, variants/serials, reconciliation, concurrency up to 6 parallel — §9); transfers/online-order races, lots/expiry, restore test still open |
| 5. Playwright E2E | Executed as ad-hoc JavaScript suites outside the repo (§4); **not yet a repo-owned TypeScript project** |
| 6. Regression | Executed per change (§4); no matrix/CI yet |
| 7. Performance and scale | **NOT EXECUTED** (only a storage model, §5) |
| 8. Security and reliability | Tenant isolation: generated route probe + body-id attacks executed, **0 leaks** (§9, F). Auth/session/CSRF/rate-limit/XSS/SQLi/upload, dependency audits, restore test: **NOT EXECUTED** |
| 9. CI/CD and production readiness | **NOT STARTED** — there is no CI in the repository |

**Provisional recommendation: *Ready for limited production*, which is what it already is** (4 paying shops, ~11 bills/day at the busiest shop). **Not ready** to be sold as "scales to 100k+ variants / many concurrent users" until Phases 7–9 are executed. One operational defect (R1 below) should be fixed first, regardless of testing.

---

## 1. Phase 1 — what the system is (measured, not assumed)

| Item | Finding | How it was measured |
|---|---|---|
| Backend | Laravel 12, PHP 8.2.12 locally, **PHP 8.3.37 in production** (version mismatch) | `composer.json`, `php -v`, production runtime |
| Frontend | Inertia 2 + Vue 3 + Vite + Tailwind; PWA service worker; Android WebView/TWA wrapper; separate Kotlin "Zaylotix Printer" helper app | `package.json`, repo tree |
| Size | 95 models · 121 controllers · 217 migrations · 70 console commands · 23 policies · 110 Vue pages · 432 routes (336 under `/app`, 71 admin, 9 api) | `route:list`, file counts |
| Database | MariaDB 11.8.9 in production; MariaDB 10.4 locally; **PHPUnit runs on SQLite in-memory** | `phpunit.xml`, `SELECT version()` |
| Tenancy | `shop_id` global scope (`BelongsToTenant`), business-root grouping for branches, owner can switch branch, staff are fixed to one shop | code + tests |
| Stock model | Product + variant stock; optional **shared stock pool** between a main shop and branches (rows are *copied per branch*, written together by `applyStockDelta`); lot/"source line" tracking for suppliers; serials; batches/expiry | code |
| Costing | Not FIFO. Variant/product cost is the current cost; a sale line **snapshots the cost at sale time**; supplier stock value = stock × cost, or per lot where lot tracking is on. Needs verification against the ledger (T-INV-02). | code reading — **to be verified by test** |
| Background work | `schedule:run` (9 daily tasks + a per-minute `queue:work --stop-when-empty`), `queue=database`, `cache=database`, `session=database` — i.e. one cron hit drives *everything* | `routes/console.php`, `.env` drivers |
| Exports | maatwebsite/excel, dompdf, mpdf, mysqldump-php | `composer.json` |
| Existing tests | **287 PHP test files, 1,992 tests, 11,875 assertions** (all passing, SQLite) · **44 JS files, 402 tests** (all passing) | executed 2026-11-27 |
| CI/CD | **None in the repository** (no `.github`, no GitLab/Jenkins file). Deploy is manual over SSH with a pre-deploy backup | `ls -a`, deploy practice |
| Tooling gaps | No coverage driver installed (no Xdebug/PCOV), no static analysis (PHPStan/Larastan), no code style gate, no Playwright project in the repo, no load-test tool in the repo | `php -m`, repo tree |

### Production facts (read-only, 2026-11-27)

* Database: 104 tables, **17.4 MB** (data 8.6 + index 8.8); 47 tables empty. 9,985 variant rows (9,613 live), 1,185 product rows (8.4 variants/product). Images: **15 files, 4.5 MB, average 306 KB**; only 20 rows carry a photo.
* Traffic actually seen: 772 bills in 189 days across all shops; busiest shop ≈ 10.6 bills/day; busiest single day 42 bills.
* Shared MariaDB server: `max_connections = 2000`, **`Max_used_connections = 2020` since its last restart 41 days ago** (server-wide, other tenants included). One burst of 13 failed requests in ~1 s ("[2002] Operation not permitted") was logged on 2026-11-26.
* Backups: 33 files / 144 MB in `storage/app/private/backups`; **no retention code** (`BackupDatabase` never deletes old files).
* `shell_exec` is disabled on the host, so CPU/RAM/crontab cannot be read from inside the app (capacity must be read from hPanel).

---

## 2. Highest-risk areas (ranked)

Status: **CONFIRMED** = reproduced/measured · **SUSPECTED** = strong indication, not yet proven.

| # | Risk | Severity | Status | Evidence |
|---|---|---|---|---|
| R1 | **The scheduler and queue worker have not run since the cloud migration (2026-11-14).** One missing cron entry stops: nightly backups, low-stock alerts, daily owner summary, payment reminders, **subscription expiry**, installment late fees, online-order reconciliation, owner sale notifications and shop self-service backups. | **Critical (operational)** | **CONFIRMED** | Nightly 02:00 backups exist for 8–14 Nov and **none after**; last notification of any kind 2026-11-14 09:00:05 while sales continue to 2026-11-27; 2 `SaleMade` jobs queued 2026-11-14 17:37 are still unprocessed (attempts 0); `schedule:list` shows the schedule is registered, so the code is fine — the cron call is missing. **Needs a hPanel cron (user action).** |
| R2 | **Tests cannot see database-engine problems.** All PHPUnit tests run on SQLite. Real production defects this engagement that SQLite could not catch: `sale_payments.method` silently lost `'gateway'` for 5 weeks (MySQL ENUM), a `GROUP BY` that MariaDB 10.4/MySQL 8 reject, `DATE_FORMAT`. Row locking and deadlock behaviour are not testable at all in the suite. | High | **CONFIRMED** | incidents `U6QFBH`/`W7SJ5W`, local MariaDB 10.4 run |
| R3 | **No CI quality gate.** Nothing runs tests before a deploy; production runs PHP 8.3, tests run on 8.2. | High | **CONFIRMED** | no pipeline file; version check |
| R4 | **Duplicate billing.** A page remount after the confirm sheet closed left an already-billed cart on screen ("press bill again"). Fixed and deployed. Production still holds 17 pairs of identical bills by the same cashier within 3 minutes (5 of them 2–7 s apart) that need owner review. | High | **CONFIRMED (cause)** · **SUSPECTED (how many real duplicates)** | browser reproduction; production read-only query |
| R5 | **Cross-branch accounting.** Three reporting bugs found in the branch/pool logic (voided bills counted in branch reports; a refund taken at the sibling counter missing from the product table; returns booked in the wrong branch). Policy now changed to "return at the selling shop" (committed, **not deployed**). | High | **CONFIRMED** (fixed) | tests + production data |
| R6 | **Unbounded growth.** Low-stock alerts create one notification row per low item × owner × day, never pruned: 3,351 rows in 18 days at today's size (the largest table, 22% of the DB). Backups also unbounded. The de-dup query for the alert job reads `data->product_id` (JSON path, no index) once per product → quadratic as the table grows. | Medium–High | **CONFIRMED** (growth) · **SUSPECTED** (job slowdown at scale) | table sizes; `SendLowStockAlerts` |
| R7 | **Sessions, cache and queue share the business database; the shared server has hit its connection ceiling.** | Medium | **CONFIRMED** (facts) · **SUSPECTED** (user impact) | `Max_used_connections`; incident burst |
| R8 | **Tenant isolation** relies on a global scope plus hand-written `withoutGlobalScopes()` in many controllers (it caused R5). 11 cross-shop probes passed; the other ~330 `/app` routes are untested for IDOR. | Medium | **SUSPECTED** | spot checks only |
| R9 | **Costing/valuation semantics unproven.** Supplier stock value is not FIFO; the expected values in the brief (e.g. 100 bought, 40 sold, 5 returned) have not been asserted against the ledger. An over-count (৳25,000 at one shop) was found and fixed earlier. | High | **SUSPECTED** | T-INV-02 |
| R10 | **Restore never tested.** Backups exist, but no restore into a clean database has been run. | High | **NOT TESTED** | — |
| R11 | **Scale behaviour unmeasured.** Full-catalogue POS mode switches to search mode above 1,500 products; stock-value, exports and several reports aggregate in PHP. No load or soak test has ever been run. | Unknown | **NOT TESTED** | — |

---

## 3. Load balancing — is it needed?

**Not on current evidence.** Load testing (how much one node can take) and load balancing (spreading traffic over several nodes) are different things.

* The measured real traffic is tiny (busiest day 42 bills). The application and database sit on one hosted node; there is nothing to balance *between*.
* The observed pressure points are not CPU-bound web traffic: they are the shared database's connection ceiling (R7), the single cron entry (R1) and per-request query counts (currently 13–31 queries for the main screens, measured).
* A load balancer would not help with R1, R6 or R7.
* **Decision rule for later (to be filled by Phase 7 measurements):** consider horizontal scaling only if sustained PHP-worker saturation or p95 latency above target is *measured* at the traffic level the business expects. Until then the work is: measure, fix queries, bound growth.

---

## 4. Tests actually executed so far (with results)

Environment key: **PHPUnit** = SQLite in-memory, PHP 8.2 · **QA-DB** = isolated local MariaDB 10.4 database `zaylotix_qa`, real browser (Chrome via Playwright, mobile viewport) against `php artisan serve` · **PROD-RO** = production, read-only.

| Suite | Env | Result |
|---|---|---|
| Full PHP test suite | PHPUnit | **2,058 passed**, 0 failed (13,791 assertions) — includes the 61 Phase 3 tests, §9 |
| Full JS suite | Vitest | **402 passed**, 0 failed |
| Full migration chain from empty database | QA-DB | passed (28 s) |
| Self-check + every parameterless `/app` page, 4 paid shops | PROD-RO | 79 pages × 4 shops, **0 FAIL**; 1 SLOW (`serial-settings`, 135 queries, 63 ms) — fixed locally, not deployed |
| Real-browser page sweep | QA-DB | 78 pages: 50 OK, 27 feature-gated 403 (expected), 1 HTTP 500 (`/app/online-orders`, MariaDB 10.4 `GROUP BY`) → fixed, deployed |
| Checkout via UI, DB verified (stock, pool, payment rows, stock value) | QA-DB | 9/9 |
| Void + return via UI, DB verified | QA-DB | 11/11 |
| First action after login (CSRF) | QA-DB | 3/3 (after fix) |
| Receipt shown after billing, billed cart does not return | QA-DB | 6/6 (after fix) |
| Double-tap / triple-click billing, offline sale then sync | QA-DB | 8/8 |
| Cross-shop isolation (anonymous, other shop, admin panel) | QA-DB | 11/11 + 3/3 |
| Hardware-scanner input path | QA-DB | 4/4 |
| Branch 2: sales, one invoice series, 10 concurrent sales, last-unit race, staff lock | QA-DB | 17/17 |
| Branch 2: catalogue sync, price sync, damage/purchase/stock-count at the branch, return, wrong-shop pin, shared customer, final parity | QA-DB | 25/26 — the one FAIL is a QA-fixture artefact (demo articles have no manufacturer/cost code, so a later-added size gets no barcode) |
| Search-box barcode typing | QA-DB | 7/8 — the FAIL is a test-semantics mistake (a human typing in the search box searches by name; scanners use a separate path, covered above) |
| Receipt QR (default off, shop QR on, no rating QR) | QA-DB | 10/10 |
| Online order discount (line + overall), invoice, COD, stock | QA-DB | 16/16 |
| Exchange money: costlier, cheaper, equal, line discount, bill discount, new-item discount, bKash, credit sale refund, online order, double submit | QA-DB | 33/33 (after fixing two test-side mistakes) |
| Online lifecycle: cancel, deliver, return; profit page vs raw rows vs P&L | QA-DB | 21/21 |
| Return/exchange only at the selling shop (screen + server + normal path) | QA-DB | 17/17 |
| Offline + online + total profit equals the P&L gross profit | PROD-RO | identical for all 4 paid shops, today / this month / 30 days (difference 0.00); product-table reconciliation showed a ৳350 gap at one shop → traced and fixed (not yet deployed) |

**Defects found and fixed during this engagement:** billed cart returning after checkout (R4); stale CSRF token after login; `/app/online-orders` 500 on stricter SQL; voided sales counted in branch reports; refund at the sibling counter missing from the product table; `gateway` payment method missing from the ENUM; colour case mismatch (`Pink`/`PINK`) breaking branch sync; `/app/returns` 1,400 queries; serial-settings N+1; Bluetooth bill print hanging forever.

**Limits of the above (be honest):** the Playwright scripts are plain JavaScript kept outside the repository (not yet in CI); they ran against a local MariaDB 10.4 and a seeded footwear/two-branch dataset, not against production data volumes; no run used more than 10 simultaneous requests.

---

## 5. Storage and scale model

Built **only from production measurements** (`information_schema`, 2026-11-27). Sizes are *table data + index*; the database's real on-disk footprint can be larger (the engine reports 12 MB of reserved free space inside the tables today).

Measured ratios per `product_variants` row: products 0.119 · stock intakes 0.292 · label prints 0.379 · supplier lots 0.136 · colour barcodes 0.133. Bytes per row (data + index): variant 311 · product 677 · intake 343 · label print 302 · lot 398 · colour barcode 247.
Bytes per bill (sale + lines + payments + activity rows): **≈ 1.7 KB**. Low-stock alert row ≈ 1.2 KB. Image average 306 KB.

Assumptions: (1) tiers count **rows in `product_variants`** — a pooled branch holds its own copy of every variant, so a two-branch business is ×2 of its catalogue; (2) 8.4 variants per product (measured); (3) the "high" column adds 30% to index bytes for random-key indexes (barcodes) — an estimate, not a measurement; (4) alert rows scale with the share of variants that are low on stock (1.9% of variants per day, measured while cron ran) and are never pruned; (5) the dump is ≈ 34% of table size (5.9 MB / 17.4 MB), catalogue-only here; (6) bill volumes are *scenarios*, not forecasts.

| tier | variants | products | catalogue, DB (low – high) | one year of bills at 100 / 1,000 / 5,000 per day | low-stock alerts per year (unpruned) | one nightly dump (catalogue) | images if **every** product had 1 photo |
|---|---:|---:|---|---|---|---|---|
| today (measured) | 9,985 | 1,189 | 7 – 8 MB | 58 MB / 584 MB / 2.85 GB | 82 MB | ~3 MB | 356 MB |
| 10,000 products | 84,000 | 10,000 | 56 – 65 MB | same | 691 MB | ~22 MB | 2.9 GB |
| 100,000 variants | 100,000 | 11,905 | 66 – 78 MB | same | 822 MB | ~26 MB | 3.5 GB |
| 500,000 variants | 500,000 | 59,524 | 330 – 389 MB | same | 4.0 GB | ~132 MB | 17.4 GB |
| 1,000,000 variants | 1,000,000 | 119,048 | 661 – 777 MB | same | 8.0 GB | ~264 MB | 34.8 GB |

Reading it: **storage is not the constraint.** The catalogue itself is under 1 GB even at a million variants. What actually grows fastest is *bills* (at 5,000/day, 2.9 GB/year) and *unpruned alert rows* (already larger per year than the whole catalogue). Images are a policy question: today 0.2% of rows have a photo. Backup storage is unbounded because nothing deletes old dumps (30 daily dumps of a 1M-variant database ≈ 8 GB before transactions).
Query time, not bytes, is the open question at scale — that is Phase 7.

---

## 6. Phase 2 — the testing roadmap

Priority: P0 = before anything else · P1 · P2. Risk = business impact if the scenario fails. "Auto" = can run unattended in CI.

### Phase 3 — critical functional (P0)

| ID | Category | Module | Scenario | Expected result | Risk | Tool | Auto | Pri | Depends | Pass / fail |
|---|---|---|---|---|---|---|---|---|---|---|
| F-01 | Blackbox/Functional | POS checkout | valid, empty cart, qty 0/negative/huge, oversell flag, split tender, credit sale, wrong shop pin, duplicate client UUID | totals, stock, cash, due, payment rows consistent; invalid input → 422 with message | Critical | PHPUnit + Playwright | Yes | P0 | seeded shop | every assertion holds; no 5xx |
| F-02 | Functional | Returns/Exchange | all exchange permutations (done manually, 33 cases) + partial returns, damaged/lost, window expiry, owner override | money/stock/ledger/reports agree | Critical | PHPUnit | Yes | P0 | F-01 | cash delta = documented formula |
| F-03 | Functional | Purchases & returns to supplier | cash/bank/credit, receive, partial receive, supplier return, cost change | payable, cash, stock, lot rows consistent | Critical | PHPUnit | Yes | P0 | — | ledger = stock |
| F-04 | Functional | Customer dues | partial payment → due → full payment → advance → void payment | customer balance, cash, per-invoice view | High | PHPUnit | Yes | P0 | F-01 | balances equal sum of entries |
| F-05 | Functional | Online orders | create, discount, cancel, deliver, RTO, COD settle | stock and profit move only at the documented states | High | PHPUnit + Playwright | Yes | P0 | — | as §4 |
| F-06 | Functional | Branch/pool | sale/return/transfer/damage/count at either branch | pooled stock identical; books separate | Critical | PHPUnit + MariaDB | Yes | P0 | MariaDB job | zero parity mismatches |
| F-07 | Whitebox | Money maths | every `round()/min()/max()` on discounts, VAT, service charge, loyalty, coupons | boundary values never go negative or exceed total | High | PHPUnit (+ property tests) | Yes | P1 | — | invariants hold for generated inputs |

### Phase 4 — stock, supplier, variant, serial integrity (P0)

| ID | Category | Module | Scenario | Expected | Risk | Tool | Auto | Pri | Depends | Pass / fail |
|---|---|---|---|---|---|---|---|---|---|---|
| T-INV-01 | Integrity | Stock ledger | Opening + in − out = closing after every movement type (sale, void, return, exchange, damage, count, transfer, purchase, supplier return) | identity holds per variant, per branch | Critical | PHPUnit + SQL invariant command | Yes | P0 | — | 0 violations |
| T-INV-02 | Integrity | Supplier valuation | the brief's example (100 @ ৳100, sold 40, returned 5 → 55 left) under single supplier, many suppliers, many cost levels, lot-tracked and untracked shops; supplier change | value = ledger; costing method documented | Critical | PHPUnit | Yes | P0 | confirm method with owner | matches hand-computed values |
| T-INV-03 | Integrity | Variants/barcodes | duplicate cell, case/space variants (found `Pink`/`PINK`), unique barcode per shop, delete with history, bulk create 1,000+ | no duplicates; history intact | High | PHPUnit | Yes | P0 | — | 0 duplicates |
| T-INV-04 | Integrity | Serial/lot/expiry | duplicate rejection, sale/return/transfer of a serial, lot FEFO, expired stock blocked | one owner per serial; expired never sold | High | PHPUnit (MariaDB) | Yes | P1 | — | no double-allocation |
| T-INV-05 | Concurrency | Pool writers | N parallel sales/purchases/returns/transfers on one variant, last-unit race, deadlock retry | no negative stock, no lost update, exactly-once | Critical | Playwright/HTTP workers vs MariaDB; PHP parallel | Partly | P0 | MariaDB service | final stock = initial − Σ accepted |
| T-INV-06 | Integrity | Reports ↔ ledger | stock value, supplier report, profit by channel, product profit, branch compare | each equals an independent SQL computation | High | SQL oracle + PHPUnit | Yes | P0 | — | difference 0.00 |

### Phase 5 — Playwright E2E (P0/P1)

| ID | Category | Module | Scenario | Expected | Risk | Tool | Auto | Pri | Depends | Pass / fail |
|---|---|---|---|---|---|---|---|---|---|---|
| E-01 | E2E | Journeys 1–7 of the brief | product→purchase→stock→sale; supplier valuation; variant→barcode→billing; partial pay→due→full pay; sale→return→reconcile; branch transfer; report→filter→PDF/Excel | each ends with DB-verified state | Critical | Playwright (TypeScript) + `data-testid` | Yes | P0 | repo-owned project, seed command | green; no console errors; no failed critical request |
| E-02 | E2E | UI hygiene | dead links, stuck spinners, horizontal overflow at 360–414 px, overlapping sheets, missing validation, double submit | none | Medium | Playwright | Yes | P1 | — | zero findings |
| E-03 | E2E | Exports/imports | PDF/CSV/Excel content equals screen; CSV/XLSX import with bad rows | file totals = DB; bad rows rejected with reason | High | Playwright + file parsing | Yes | P1 | — | totals identical |
| E-04 | E2E | Offline | sell offline, reconnect, branch switch while queued | exactly-once sync; wrong-shop 409 | High | Playwright network control | Yes | P0 | — | as §4 |
| E-05 | Compat | Browsers/devices | Chrome Android (primary), Samsung Internet, Safari iOS, desktop Chrome/Edge/Firefox; WebView | core journeys work or fail gracefully | Medium | Playwright projects + real-device spot check | Partly | P2 | — | matrix filled |

### Phase 6 — regression (P0)

| ID | Category | Scenario | Expected | Risk | Tool | Auto | Pri | Depends | Pass / fail |
|---|---|---|---|---|---|---|---|---|---|
| G-01 | Regression | Change→module dependency matrix (variants, billing, schema, reports) and the test sets each triggers | matrix in repo; every PR runs its set | High | script + CI | Yes | P0 | CI | matrix enforced |
| G-02 | Regression | Baseline comparison before blaming a change | failures reproduced on the previous commit | Medium | CI matrix | Yes | P1 | CI | — |

### Phase 7 — performance and scale (P1; **not executed**)

| ID | Category | Scenario | Expected | Risk | Tool | Auto | Pri | Depends | Pass / fail |
|---|---|---|---|---|---|---|---|---|---|
| P-01 | Scale data | factory/seeder generates 10k products, 100k/500k/1M variants with intakes, labels, lots, serials, sales, notifications in an isolated MariaDB | reproducible dataset | — | Laravel factories/chunked inserts | Yes | P1 | MariaDB 11.8 container | dataset loads |
| P-02 | Performance | login, dashboard, product/variant search, barcode lookup, POS open, stock page, supplier valuation, accounts, reports, PDF/Excel export | p50/p95/p99, query count, memory per endpoint at each tier | High | k6 + query log + `EXPLAIN` | Yes | P1 | P-01 | thresholds agreed *before* running |
| P-03 | Load | 10/50/100/(500 if the box allows) mixed-behaviour users: billing, search, stock, reports | error rate, p95 vs target; first bottleneck named | High | k6 | Yes | P1 | P-01 | targets agreed first |
| P-04 | Stress/spike/soak | ramp to failure, 10× spike, 8–12 h soak; recovery; connection exhaustion; queue backlog; memory growth | no data corruption after overload; recovery without manual steps | High | k6 + monitoring | Partly | P2 | P-03 | none lost/duplicated |
| P-05 | Optimisation | missing indexes, N+1, unbounded queries, PHP-side aggregation (stock value, exports), the low-stock de-dup query | each fix justified by a measurement | Medium | `EXPLAIN ANALYZE`, slow log | No | P1 | P-02 | measured gain |

### Phase 8 — security and reliability (P1; mostly **not executed**)

| ID | Category | Scenario | Expected | Risk | Tool | Auto | Pri | Depends | Pass / fail |
|---|---|---|---|---|---|---|---|---|---|
| S-01 | Security | IDOR sweep over all ~336 `/app` routes: every id-taking route probed with another shop's / branch's ids and as cashier | 403/404, nothing readable or writable | Critical | generated probe from `route:list` | Yes | P0 | two seeded shops | 0 leaks |
| S-02 | Security | auth/session/CSRF/rate-limit/password reset; cookie flags; mass assignment; file upload types/size; SQLi/XSS on every free-text input | refused or escaped | High | Playwright + ZAP baseline (local) | Partly | P1 | — | no confirmed finding |
| S-03 | Security | exports contain only authorised data; staff permission matrix enforced server-side | as stated | High | PHPUnit | Yes | P1 | — | matrix verified |
| S-04 | Security | `composer audit`, `npm audit` | no critical advisory | Medium | CLI | Yes | P1 | — | none critical |
| B-01 | Recovery | restore the newest dump into an empty database, run self-check + a business journey | restored system works | Critical | mysql + self-check | Yes | P0 | — | self-check passes |
| B-02 | Recovery | failed deploy / migration rollback rehearsal | previous state recoverable | High | local rehearsal | Partly | P1 | — | documented and timed |
| B-03 | Reliability | cron/queue liveness: alert if no scheduled run for 24 h; failed jobs; retry; duplicate execution; DB-down behaviour | silent failure impossible | High | health endpoint + external check | Yes | P0 | R1 fixed | alarm fires in a drill |
| B-04 | Reliability | retention for backups, notifications, activity logs | bounded growth | Medium | command + test | Yes | P1 | decision | size stays flat |

### Phase 9 — CI/CD and production readiness (P0 for the pipeline itself)

| ID | Category | Scenario | Expected | Risk | Tool | Auto | Pri | Depends | Pass / fail |
|---|---|---|---|---|---|---|---|---|---|
| C-01 | CI | GitHub Actions: PHP 8.3, MariaDB 11.8 service, `composer audit`, syntax, PHPUnit **on SQLite and on MariaDB**, Vitest, build, Playwright critical set, artifacts | stops on any failure | High | GitHub Actions | Yes | P0 | repo on GitHub (it is) | red blocks merge |
| C-02 | CI | staging smoke + `zaylotix:self-check --sweep` after deploy; manual approval before production; secrets only in the secret store | no unapproved deploy | High | Actions environments | Yes | P1 | staging target | gate observed |
| C-03 | Readiness | pre-deploy backup, migration compatibility, rollback note, cache/queue restart, health check | checklist enforced | High | script | Yes | P1 | — | checklist green |

---

## 7. Decisions / approvals needed (updated 2026-11-27 — Phase 3 is done; see §9 for the new D-items)

0. **New, from Phase 3:** approve (or reject) each fix proposed in §9.3, and answer the three owner decisions D2, D7, D12 (and D8 if a warning is wanted). No business-critical code has been changed.
1. **Cron (R1)** — add one Hostinger cron entry that calls `php artisan schedule:run` every minute for the production app. Everything in R1 resumes (including nightly backups and subscription expiry). *Owner action; nothing in the code needs to change.*
2. **Environment for MariaDB-backed tests** — a local/CI MariaDB 11.8 matching production (the local server is 10.4).
3. **Costing method** — confirm how supplier stock value must be computed for non-lot shops (current cost vs lot cost) so T-INV-02 asserts the right thing.
4. **Retention policy** — how long to keep backups, alert notifications and activity logs (R6).
5. **Performance targets** — agreed p95 / error-rate numbers *before* Phase 7 is run.
6. **Deploy of `d7705fba`** (ZV-sticker resolver recursion fix) — **not deployed; awaiting the owner's explicit go.** (`15742b8f` was deployed on request and is part of production `1a6a0fb9`.)
7. Any code change to business-critical logic will be proposed first (root cause, modules, fix, regression risk) and **will not be made without approval**.

## 8. NOT EXECUTED (explicitly)

Load, stress, spike and soak tests · any run above 6 simultaneous requests (Phase 3 raced up to 6; the earlier branch test used 10) · scale datasets (10k–1M variants) · restore test · XSS/SQLi/upload/rate-limit probes · dependency audits · browsers other than Chrome · real-device WebView · import/export file-content tests · CI pipeline (none exists) · coverage measurement (no driver installed) · MariaDB 11.8 (all local MariaDB runs are 10.4) · see §9.5 for the Phase 3 items that were planned but not run.

---

## 9. Phase 3 — critical functional testing (executed 2026-11-27)

Rules followed: nothing was run against production; PHPUnit uses an in-memory SQLite database; the concurrency harness refuses to start on any database but `zaylotix_qa`; **no business-critical code was changed** — every defect below is a *proposal waiting for approval*. Every figure in a test is read straight from the tables (independent oracle), never from the application's own report code, unless the report *is* what is being tested.

### 9.1 What was executed

| Area | File(s) | Method | Passed | Defect reproductions (fail by design) |
|---|---|---|---|---|
| A. Sales, billing, payments, dues, refunds | `tests/Feature/Phase3/P3SalesMoneyTest.php` | sale→void round trip for 5 bill shapes (plain, decimals+line discount, bill discount, split cash+bKash, credit); full return; partial return then void; credit-sale due/cash split; 10 refused-checkout shapes with zero side effects; last pieces; replayed `client_uuid`; customer payment + void + overpayment; loyalty points earn/return/void | 14 | 2 (D1, D2) |
| B. Purchases, supplier returns, balances | `P3PurchaseSupplierTest.php` | cash/credit purchase ↔ supplier return round trips; payable arithmetic to the cent; overpaying a supplier refused; pending purchase received once; weighted-average blend; latest-cost overwrite; past bills keep their cost snapshot; refused purchases; supplier delete/merge | 12 | 1 (D3) |
| C. Stock movement and reconciliation | `P3StockIdentityTest.php` | **seeded random days** (3 seeds × 45 steps of sell/void/return/purchase/supplier-return/damage/count) checked after every step against an independent model, *size stock and article stock*; damage beyond stock refused; count absolute; transfers conserve stock; transfer outside the business refused | 7 | 0 |
| D. Supplier-wise valuation | `P3SupplierValuationTest.php` | the brief's example (100 @ ৳100, sold 40, 5 returned to the supplier → 55 × 100 = **৳5,500** for that supplier, card = report = hand value); two suppliers at two prices; lot depletion order; return into the exact lot; damaged return; stock count FIFO; supplier-return lot priority | 9 | 3 (D4, D5, D6) + 1 pinned decision (D7) |
| E. Variants, barcodes, serials | `P3VariantBarcodeSerialTest.php` | colour×size grid; double/re-cased submits never duplicate a size+colour; barcode unique per shop but reusable across shops; delete releases the barcode and keeps history; serial sold→returned→sold, void restores the unit, damaged return does not | 9 | 1 (D9) + 1 pinned decision (D8) |
| F. Tenant and branch isolation | `P3TenantIsolationTest.php` | static audit of every model with `shop_id`; **generated probe** of every `/app` route that binds a model, all verbs, aimed at another shop's rows (**139 requests, 40 model classes, 0 leaks**, one route answered 422 = inconclusive); positive control (the owner reaches real pages with the same probe); **15 valid-payload attacks with another shop's ids in the body** (checkout, return, void, payments, purchase, damage, count, transfer…) → books unchanged; sibling branch cannot void/edit/stock-in | 5 | 0 |
| G. Concurrency (real parallel processes on MariaDB) | `tests/QA/concurrency/run.php` + `worker.php` | 11 scenarios × 3 rounds against `zaylotix_qa` (Baby Park main + pooled branch): last piece (same shop, across both branches), same bill ×6, void ×5, return ×5, return-vs-void, purchases ×6, expenses ×6, supplier returns ×6, due collection (same customer ×6, different customers ×4 and ×2) | 8 scenarios clean (24 rounds) | 3 scenarios fail (S6, S8, S10 → D10) |
| H. Report and financial reconciliation | `P3ReconciliationTest.php` | **seeded random days** (3 seeds × 40 steps through 10 money screens): *cash + customer dues* and *supplier payables* rebuilt from raw ledgers after every step; stored bill profit = arithmetic of its lines; profit report = stored profit − refunds + returned cost (live bills) | 5 | 2 (D12, D13) |

**Totals.** New PHPUnit tests: **61 executed and passed** (default run), **9 executable defect reproductions** (run with `php artisan test --group=phase3_defect`; they fail today and are excluded from the default run in `phpunit.xml`). Harness: **33 race rounds**, 24 clean, 9 with deadlock errors. **Full PHP regression after adding all of this: 2,058 passed, 0 failed (13,791 assertions)** — before Phase 3 it was 1,997 (11,883), so the difference is exactly the 61 new tests; the 9 defect reproductions are excluded from that run by design. The JS suite was not re-run (no JS file changed; 402 passing at its last run). Blocked: none. Not executed: §9.5.

### 9.2 Confirmed working (evidence, not assumption)

- A sale followed by its void restores cash, bank, size stock, article stock, customer due and loyalty points exactly, for plain, decimal, discounted, split-tender and credit bills.
- A refused checkout (over/under-payment, quantity beyond stock, fractional/zero/negative quantity, negative discount, unknown product, empty cart, unknown payment method) changes nothing at all and answers a clean 4xx, never a 5xx.
- The same bill sent six times at the same instant creates **one** bill, takes the stock and cash **once**.
- One piece left, six cashiers (same shop) or three at each of the two pooled branches: **exactly one sale**, stock ends at 0, never negative, cash moves once.
- The same bill voided by five tabs: reversed once. The same piece returned by five tabs: refunded once. A return racing a void: books whole either way.
- Stock = independent model after every applied step of 3 seeded random days (45 steps each, size and article); article stock = sum of its sizes at every step.
- Cash + customer dues and supplier payables equal the ledgers after every applied step of 3 seeded random days (40 steps each; a step is skipped when it does not apply, e.g. nothing to void yet).
- Supplier valuation: the brief's example gives ৳5,500; lots follow sales, returns, counts and supplier returns exactly.
- A stranger shop gets 403/404 from every probed route and cannot move money or stock with another shop's ids in the body; the other shop's tables were byte-identical afterwards.

### 9.3 Bug register (all **open**; nothing has been fixed)

Severity = business impact. "Approval" = I will not touch this until you say so.

| ID | Sev | What happens (reproduced) | Root cause | Proposed fix | Regression risk | Re-test after fix |
|---|---|---|---|---|---|---|
| **D13** | **Medium** | A bill with a return is later **voided**: the profit report keeps subtracting the refund. Example: sale 200, return 1 piece (100), void → sales 0 but *returns 100* → gross profit **−40** on a day with no real sale. Cash is correct; only the report is wrong. | `Reports::rangeStats()` sums `returns` by date without checking that the bill still counts (bills do — `voided_at IS NULL`). Voiding after a return is allowed since Phase 39. | Exclude returns whose bill is voided/not-counted (join on `sales`) in the return totals of `rangeStats`, `combinedRangeStats` and the product/profit reports that sum `returns`. | Medium-low (report arithmetic only). | defect test D13; `profitByChannel` = P&L check on production (read-only); existing report tests |
| **D10** | **Medium** (availability, no data loss) | Two or more people entering a **purchase**, an **expense**, or collecting **dues from different customers** within the same few milliseconds: one request fails with the "সার্ভারে সাময়িক সমস্যা হয়েছে… (রেফারেন্স …)" screen (HTTP 500, MariaDB deadlock 1213). Measured: purchases ×6 → 4 failed; expenses ×6 → 2–5 failed; dues ×4 → 3 failed; dues ×2 → 1 failed (10/10 rounds). **The books stayed exact in every round** (stock, cash, due all matched the accepted requests). | A child row (`purchases`, `expenses`, `payments` …) is inserted first (MariaDB takes a *shared* lock on the parent `shops` row for the foreign key), then `UPDATE shops SET cash_balance` needs an *exclusive* lock — two such requests wait on each other. Paths that lock the shop row first (POS checkout, returns, void, loans, salary, partners, rentals) are immune — S1–S5 and S9 are clean. | Lock the shop row first inside the transaction (`Shop::whereKey(id)->lockForUpdate()`, the discipline POS already uses) **or** wrap in `DB::transaction($cb, 3)` so a deadlock is retried transparently. Call sites: `ExpenseController:63`, `PurchaseController:176`, `PaymentController` (collections), plus by code reading only: `SupplierPaymentController:43`, `AdSpendController:124`, `SupplierPayable:48`, `PurchaseOrderController:195`. | Medium — lock order must stay shop → customer/supplier → product everywhere. | harness S1–S10 ×10 rounds; full PHPUnit |
| **D4** | **Medium** (lot-tracked shops, e.g. SEAMAN) | Voiding a bill gives the pieces back to the size and the article but **not to the supplier lot** they came from → lots add up to less than the shelf; the supplier report moves the difference to the article's supplier/"unknown"; scanning that lot's sticker says "out of stock". | `SaleReversal::reverse()` has no `product_source_id` handling (the return path has it). | Restore the not-yet-returned quantity into `sale_items.product_source_id`'s lot, as `ReturnController` already does. | Low-medium. | defect test D4; `SupplierStockValueTest`; sale→void→lots round trip |
| **D5** | **Medium** (lot-tracked shops) | A damage write-off lowers the size/article stock but **no lot** → lots run *ahead* of the shelf. With D4 this is the likely source of the 2026-11-25 SEAMAN case (46 in lots, 22 on the shelf). | `DamageController` never touches `product_sources`, although `damages.product_source_id` exists. | Same FIFO decrement `StockCountController` uses (or the lot named on the damage). | Low-medium. | defect test D5 |
| **D12** | **Medium — owner decision** | Credit bill 150 (10 paid). Customer pays 100 at the due screen *without choosing an invoice*. Bill is voided. Due → 0, but the 100 stays in the drawer and is **recorded nowhere as the customer's** (advance stays 0). Collections that *are* linked to the invoice do become advance (Phase 39). | `SaleReversal` deliberately ignores unlinked collections ("can't be attributed to this bill"), and clamps due at 0. | Decide: turn the excess into advance/store credit (recommended), or refund, or leave. | Low if advance. | defect test D12; reconciliation random day with voids enabled |
| **D3** | **Medium** | Goods received through the **Purchases** screen never reach the intake ledger (the Stock page's stock-in does) → "how much came in" per supplier is understated. | `PurchaseController::applyEffects()` never calls `IntakeLedger::record`. | Record in both the variant and plain paths. | Low. | defect test D3; `SupplierFlowReportTest` |
| **D2** | **Medium — owner decision** | A bill paid by **bKash** is refunded: the bank balance keeps the money, the cash drawer pays it (bank +100 too high, cash −100). | Refunds always debit `cash_balance`, the tender credited `bank_balance`. | Decide: refund through the original channel, or keep "from cash" but move the same amount bank→cash in the books. | Medium (money). | defect test D2; exchange matrix (33 cases) |
| **D6** | Medium-low | Supplier report: *received* is attributed by the lot's supplier, *sold* by the **article's** supplier → for an article bought from several suppliers the per-supplier "ever received/sold" figures are wrong, and sold pieces can show as "supplier unknown". | `AccountsController::supplierFlows()` uses `COALESCE(m.supplier_id, p.supplier_id)` for sales. | Resolve sold lines through `sale_items.product_source_id → product_sources.supplier_id` first. | Low (report only). | defect test D6; `SupplierFlowReportTest` |
| **D9** | Low-medium | Registering an IMEI that already exists (or twice in one pasted list) → crash screen (HTTP 500). Nothing is corrupted (rolled back, verified). | Only the database unique index stops it; no validation. | Validate the list (duplicates inside it, and against the shop's serials) and return an `imeis` error. | Low. | defect test D9; `SerialTrackingTest` |
| **D1** | Low | Three one-piece returns of one ৳290 bill refund ৳290.01 in total (1 paisa). | `lineRefundAndCost` rounds each unit's proportional share separately. | Let the last return of a line take the remainder (`paid − already refunded`). | Medium-low (central money formula). | defect test D1; exchange matrix |
| **D11** | Low (audit trail) | `returns.applied_to_due` is yes/no: **how much of a refund reduced the due vs left the drawer is not stored**, so cash cannot be rebuilt from the ledger and the void's netting has to re-derive the split proportionally. | column design | add `due_applied` / `cash_paid` amounts (additive migration; history cannot be back-filled exactly). | Low. | reconciliation test can then check cash and dues separately |
| **D7** | Decision | In a lot-tracked shop the stock-value card values every piece at the size's **latest** cost while the supplier report values lots at their own cost; the gap is shown as "supplier unknown" although all suppliers are known (example: card ৳1,600, lots ৳1,300, "unknown" ৳300). | documented latest-cost overwrite | Decide the valuation basis for lot shops (lot cost recommended). | — | pinned by `P3SupplierValuationTest` |
| **D8** | Decision | A serial that is **already sold** (or unknown) can be rung up again without a warning: stock goes down, the serial stays "sold"; shelf count 1 vs serial list 2 in stock. Documented as "never block a sale". | by design | Decide: keep / warn / block. | — | pinned by `P3VariantBarcodeSerialTest` |

### 9.4 How to re-run

- Default suite (everything above that passes): `php artisan test`.
- Defect reproductions: `php artisan test --group=phase3_defect` (currently 9 failures, one per D-item marked "defect test"; when a fix is approved and applied, remove the `#[Group('phase3_defect')]` line and the test joins the default run).
- Concurrency: `php tests/QA/concurrency/run.php [S1_ S6_ …] --rounds=3` (needs the local MariaDB and the `zaylotix_qa` database from `rebuild.sh`; refuses any other database).
- Isolation coverage detail is written to `storage/logs/p3_idor_coverage.json` on each run (not committed).

### 9.5 Planned in Phase 3 but **not** executed (be honest)

F-01: oversell flag, wrong-shop pin and huge quantities were not re-run in Phase 3 (earlier QA §4 only) · F-02: return-window expiry and owner override; exchange permutations were not re-run (33 cases ran earlier) · F-04: advance application (`apply-advance`), per-invoice view · F-05: online orders not re-run (§4) · F-06: transfer confirmation flow (accept/reject), branch comparison report oracle · F-07: generated property tests over discount/VAT/service-charge/loyalty/coupon boundaries · T-INV-03: bulk create of 1,000+ variants · T-INV-04: lot expiry/FEFO and batch tracking · T-INV-05: online-order delivery vs POS on the last piece, transfers in parallel, stock count vs sale, parallel serial sale, anything above 6 parallel, MariaDB 11.8 (all MariaDB runs are 10.4) · T-INV-06: branch comparison, accounts net-worth oracle · S-01: 13 routes whose parameters are plain strings (barcodes, provider names, export kinds), 1 route with no victim row, 1 inconclusive, and the admin (71) and api (9) routes · production: no read-only re-check was made in Phase 3.

### 9.6 Verdict and next step

**Not a production-readiness claim.** These are automated and scripted results on SQLite and a local MariaDB 10.4; they show the money, stock and isolation logic is sound under the scenarios listed, and they found 13 concrete items. The provisional recommendation in §0 stands (*ready for limited production*, which is what it is), with two items I would fix first once approved — **D13** (wrong profit figure after a void) and **D10** (crash screen under simultaneous entries) — and the cron (R1) still the most important operational item.

Suggested order if you approve fixes: D13 → D10 → D4+D5 → D3 → D9 → D6 → D1 → D11, with D2, D7, D8, D12 waiting for your decision. **Next QA phase:** finish Phase 4 (the §9.5 items, on MariaDB 11.8), then B-01 restore test and S-02 security probes, then the repo-owned Playwright project and CI.
