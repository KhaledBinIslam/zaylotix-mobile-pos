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
| 3. Critical functional testing | **Executed 2026-11-27 — results, bug register and open approvals in §9.** 14 defects/decisions registered; **D13 and D10 fixed on the owner's approval (2026-11-27)**, the rest wait for approval/decisions |
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
| Full PHP test suite | PHPUnit | **2,061 passed**, 0 failed (13,826 assertions) — includes the 64 Phase 3 tests, §9 |
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
| G. Concurrency (real parallel processes on MariaDB) | `tests/QA/concurrency/run.php` + `worker.php` | 14 scenarios against `zaylotix_qa` (Baby Park main + pooled branch): last piece (same shop, across both branches), same bill ×6, void ×5, return ×5, return-vs-void, purchases ×6, expenses ×6, supplier returns ×6, supplier payments ×4, paid stock-in ×4, due collection (same customer ×6, different customers ×4 and ×2), **every cash-moving screen at once**, **POS checkout vs supplier return of the same article** | first run (11 scenarios × 3 rounds): 8 clean · **after the D10 fix: all 14 × 5 rounds = 70 rounds clean** | before the fix 5 scenarios failed (S6, S8, S10, S12 paid stock-in, S13 mixed, S14 POS vs supplier return) → all D10, now fixed |
| H. Report and financial reconciliation | `P3ReconciliationTest.php` | **seeded random days** (3 seeds × 40 steps through 10 money screens): *cash + customer dues* and *supplier payables* rebuilt from raw ledgers after every step; stored bill profit = arithmetic of its lines; profit report = stored profit − refunds + returned cost (live bills); **10 reports against a returned-then-voided bill next to a live one (D13 fix)**; live-bill return control | 8 | 2 (D12, D14) |

**Totals.** New PHPUnit tests: **64 executed and passed** (default run: 61 from the first pass + 3 added with the D13 fix), **9 executable defect reproductions** still open (D1, D2, D3, D4, D5, D6, D9, D12, D14 — run with `php artisan test --group=phase3_defect`; they fail today and are excluded from the default run in `phpunit.xml`). Harness: first run 33 race rounds (24 clean, 9 with deadlock errors); **after the D10 fix 70 rounds, all clean**. **Full PHP regression after the D13 and D10 fixes: 2,061 passed, 0 failed (13,826 assertions)** — before Phase 3 it was 1,997 (11,883), the difference is exactly the 64 new tests; the 9 defect reproductions are excluded from that run by design. The JS suite was not re-run (no JS file changed; 402 passing at its last run). Blocked: none. Not executed: §9.5.

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

### 9.3 Bug register (fixed on the owner's approval: D13, D10 on 2026-11-27 and D2, D7, D8, D9, D12, D14, D15 on 2026-11-28 — details in §10; still **open**: D1, D3, D4, D5, D6, D11)

Severity = business impact. "Approval" = I will not touch this until you say so.

| ID | Sev | What happens (reproduced) | Root cause | Proposed fix | Regression risk | Re-test after fix |
|---|---|---|---|---|---|---|
| **D13** ✅ **FIXED 2026-11-27** | **Medium** | A bill with a return is later **voided**: the profit report keeps subtracting the refund. Example: sale 200, return 1 piece (100), void → sales 0 but *returns 100* → gross profit **−40** on a day with no real sale. Cash is correct; only the report is wrong. | `Reports::rangeStats()` sums `returns` by date without checking that the bill still counts (bills do — `voided_at IS NULL`). Voiding after a return is allowed since Phase 39. | **Done (approved):** new `SalesReturn::onLiveBills()` scope — a return counts only while its bill is not voided/not-counted (legacy returns without a bill are kept; cross-branch returns are judged by the bill they point at). Applied to every report that sums `returns`: `rangeStats`, `combinedRangeStats`, `cashierCashBreakdown`, `productStats`, `categoryReport`, `salespersonProfitReport`, `branchSaleLines`, `variantProfitReport`, `profitByChannel`, `productProfitByChannel` and the Accounts "total returns" figure. Cash, stock and the void logic are untouched. | Low-medium (report arithmetic only). | **Verified:** the former defect test now passes in the default run; a new test builds a returned-then-voided bill next to a live bill of the same products and checks 10 reports show only the live bill; a control proves a return on a live bill still reduces every figure; full suite green. Production not yet re-checked (not deployed). |
| **D10** ✅ **FIXED 2026-11-27** | **Medium** (availability, no data loss) | Two or more people entering a **purchase**, an **expense**, or collecting **dues from different customers** within the same few milliseconds: one request fails with the "সার্ভারে সাময়িক সমস্যা হয়েছে… (রেফারেন্স …)" screen (HTTP 500, MariaDB deadlock 1213). Measured: purchases ×6 → 4 failed; expenses ×6 → 2–5 failed; dues ×4 → 3 failed; dues ×2 → 1 failed (10/10 rounds). **The books stayed exact in every round** (stock, cash, due all matched the accepted requests). | A child row (`purchases`, `expenses`, `payments` …) is inserted first (MariaDB takes a *shared* lock on the parent `shops` row for the foreign key), then `UPDATE shops SET cash_balance` needs an *exclusive* lock — two such requests wait on each other. Paths that lock the shop row first (POS checkout, returns, void, loans, salary, partners, rentals) are immune — S1–S5 and S9 are clean. | **Done (approved):** one rule, one helper — `App\Support\ShopLock` (`acquire()` / `acquireIfPaid($method)`) takes the shop row as the **first** statement of the transaction, before any customer/supplier/product lock, exactly as POS already does (order: shop → customer/supplier → product). Added to: `ExpenseController`, `PurchaseController` (store + receive), `PaymentController` (collect, collect-full, void), `AdSpendController`, `PurchaseOrderController` (receive), `SupplierReturnController` (cash/bank settlement), and the five paid stock-in paths (`ProductController`, `ProductVariantController` ×2, `ProductSourceController` ×2, `OpeningStockController`). The lock is only taken when money really moves (cash/bank + a payee), so credit and unpaid stock-ins are not serialised. Untouched by design: `SupplierPaymentController` (its first statement already is the shop update; harness S11 was clean). | Medium — lock order must stay shop → customer/supplier → product everywhere. | **Verified on MariaDB 10.4 with real parallel processes:** before → purchases ×6: 4 failed, expenses ×6: 2–5 failed, dues ×4: 3 failed, paid stock-in ×4: 1–3 failed, **POS checkout vs supplier return of the same article: all 3 checkouts failed (S14)**, mixed screens at once: 3–5 of 7 failed (S13; even POS checkouts crashed). After → all 14 scenarios × 5 rounds = **70 rounds, 0 violations, 0 server errors**; every accepted request is reflected exactly in stock and cash. Full suite green. |
| **D14** ✅ **FIXED 2026-11-28 — see §10** | **Medium** (found while fixing D13) | The **multi-branch combined report** (Reports page for an owner with branches, and the company dashboard's per-branch table) counts **voided bills** as sales and profit. Reproduced: one live bill 200 + one voided bill 500 → combined sales **700** (single-shop report says 200), 2 bills instead of 1. Applies to any business with a branch (Baby Park, Liberty) whenever that owner opens the combined view; not yet measured on production. | `Reports::combinedRangeStats()` (and `companyBranchBreakdown()` through it) builds its sales query with `withoutGlobalScopes()` to reach sibling shops, which also removes `Sale`'s own "active" scope (`voided_at IS NULL AND counted_in_reports`). The branch product/variant reports were fixed for this on 2026-11-26; this one was missed. | Add the two conditions back to the combined sale query (`whereNull('voided_at')->where('counted_in_reports', true)`), as `branchSaleLines()` already does. | Low-medium (report arithmetic; the numbers the owner sees will drop by the voided bills). | defect test D14 (reproduces today); company-dashboard and branch-report tests; compare combined vs the sum of each shop's own report on production (read-only) |
| **D15** ✅ **FIXED 2026-11-28 — see §10** | Low-medium (found while building D2) | Voiding an **exchange bill** took its "trade credit" tender off the **bank balance** as if it were bank money (bank 500 → 400 in the test). | `SaleReversal` reverses every tender line that is not cash/COD into `bank_balance`; `trade_credit` — the credit of the goods the customer handed back — is not money. | Skip `trade_credit` in the reversal, like `cod`. | Low. | `P3RefundChannelTest` |
| **D4** | **Medium** (lot-tracked shops, e.g. SEAMAN) | Voiding a bill gives the pieces back to the size and the article but **not to the supplier lot** they came from → lots add up to less than the shelf; the supplier report moves the difference to the article's supplier/"unknown"; scanning that lot's sticker says "out of stock". | `SaleReversal::reverse()` has no `product_source_id` handling (the return path has it). | Restore the not-yet-returned quantity into `sale_items.product_source_id`'s lot, as `ReturnController` already does. | Low-medium. | defect test D4; `SupplierStockValueTest`; sale→void→lots round trip |
| **D5** | **Medium** (lot-tracked shops) | A damage write-off lowers the size/article stock but **no lot** → lots run *ahead* of the shelf. With D4 this is the likely source of the 2026-11-25 SEAMAN case (46 in lots, 22 on the shelf). | `DamageController` never touches `product_sources`, although `damages.product_source_id` exists. | Same FIFO decrement `StockCountController` uses (or the lot named on the damage). | Low-medium. | defect test D5 |
| **D12** ✅ **FIXED 2026-11-28 — see §10** | **Medium** (owner decision made) | Credit bill 150 (10 paid). Customer pays 100 at the due screen *without choosing an invoice*. Bill is voided. Due → 0, but the 100 stays in the drawer and is **recorded nowhere as the customer's** (advance stays 0). Collections that *are* linked to the invoice do become advance (Phase 39). | `SaleReversal` deliberately ignores unlinked collections ("can't be attributed to this bill"), and clamps due at 0. | Decide: turn the excess into advance/store credit (recommended), or refund, or leave. | Low if advance. | defect test D12; reconciliation random day with voids enabled |
| **D3** | **Medium** | Goods received through the **Purchases** screen never reach the intake ledger (the Stock page's stock-in does) → "how much came in" per supplier is understated. | `PurchaseController::applyEffects()` never calls `IntakeLedger::record`. | Record in both the variant and plain paths. | Low. | defect test D3; `SupplierFlowReportTest` |
| **D2** ✅ **FIXED 2026-11-28 — see §10** | **Medium** (owner decision made) | A bill paid by **bKash** is refunded: the bank balance keeps the money, the cash drawer pays it (bank +100 too high, cash −100). | Refunds always debit `cash_balance`, the tender credited `bank_balance`. | Decide: refund through the original channel, or keep "from cash" but move the same amount bank→cash in the books. | Medium (money). | defect test D2; exchange matrix (33 cases) |
| **D6** | Medium-low | Supplier report: *received* is attributed by the lot's supplier, *sold* by the **article's** supplier → for an article bought from several suppliers the per-supplier "ever received/sold" figures are wrong, and sold pieces can show as "supplier unknown". | `AccountsController::supplierFlows()` uses `COALESCE(m.supplier_id, p.supplier_id)` for sales. | Resolve sold lines through `sale_items.product_source_id → product_sources.supplier_id` first. | Low (report only). | defect test D6; `SupplierFlowReportTest` |
| **D9** ✅ **FIXED 2026-11-28 with D8 — see §10** | Low-medium | Registering an IMEI that already exists (or twice in one pasted list) → crash screen (HTTP 500). Nothing is corrupted (rolled back, verified). | Only the database unique index stops it; no validation. | Validate the list (duplicates inside it, and against the shop's serials) and return an `imeis` error. | Low. | defect test D9; `SerialTrackingTest` |
| **D1** | Low | Three one-piece returns of one ৳290 bill refund ৳290.01 in total (1 paisa). | `lineRefundAndCost` rounds each unit's proportional share separately. | Let the last return of a line take the remainder (`paid − already refunded`). | Medium-low (central money formula). | defect test D1; exchange matrix |
| **D11** | Low (audit trail) | `returns.applied_to_due` is yes/no: **how much of a refund reduced the due vs left the drawer is not stored**, so cash cannot be rebuilt from the ledger and the void's netting has to re-derive the split proportionally. | column design | add `due_applied` / `cash_paid` amounts (additive migration; history cannot be back-filled exactly). | Low. | reconciliation test can then check cash and dues separately |
| **D7** ✅ **FIXED 2026-11-28 — see §10** | Decision (made) | In a lot-tracked shop the stock-value card values every piece at the size's **latest** cost while the supplier report values lots at their own cost; the gap is shown as "supplier unknown" although all suppliers are known (example: card ৳1,600, lots ৳1,300, "unknown" ৳300). | documented latest-cost overwrite | Decide the valuation basis for lot shops (lot cost recommended). | — | pinned by `P3SupplierValuationTest` |
| **D8** ✅ **FIXED 2026-11-28 — see §10** | Decision (made) | A serial that is **already sold** (or unknown) can be rung up again without a warning: stock goes down, the serial stays "sold"; shelf count 1 vs serial list 2 in stock. Documented as "never block a sale". | by design | Decide: keep / warn / block. | — | pinned by `P3VariantBarcodeSerialTest` |

### 9.4 How to re-run

- Default suite (everything above that passes): `php artisan test`.
- Defect reproductions: `php artisan test --group=phase3_defect` (currently 5 failures — D1, D3, D4, D5, D6; when a fix is approved and applied, remove the `#[Group('phase3_defect')]` line and the test joins the default run).
- Concurrency: `php tests/QA/concurrency/run.php [S1_ S6_ …] --rounds=3` (needs the local MariaDB and the `zaylotix_qa` database from `rebuild.sh`; refuses any other database).
- Isolation coverage detail is written to `storage/logs/p3_idor_coverage.json` on each run (not committed).

### 9.5 Planned in Phase 3 but **not** executed (be honest)

F-01: oversell flag, wrong-shop pin and huge quantities were not re-run in Phase 3 (earlier QA §4 only) · F-02: return-window expiry and owner override; exchange permutations were not re-run (33 cases ran earlier) · F-04: advance application (`apply-advance`), per-invoice view · F-05: online orders not re-run (§4) · F-06: transfer confirmation flow (accept/reject), branch comparison report oracle · F-07: generated property tests over discount/VAT/service-charge/loyalty/coupon boundaries · T-INV-03: bulk create of 1,000+ variants · T-INV-04: lot expiry/FEFO and batch tracking · T-INV-05: online-order delivery vs POS on the last piece, transfers in parallel, stock count vs sale, parallel serial sale, anything above 6 parallel, MariaDB 11.8 (all MariaDB runs are 10.4) · T-INV-06: branch comparison, accounts net-worth oracle · S-01: 13 routes whose parameters are plain strings (barcodes, provider names, export kinds), 1 route with no victim row, 1 inconclusive, and the admin (71) and api (9) routes · production: no read-only re-check was made in Phase 3.

### 9.6 Verdict and next step

**Not a production-readiness claim.** These are automated and scripted results on SQLite and a local MariaDB 10.4; they show the money, stock and isolation logic is sound under the scenarios listed, and they found 14 concrete items. The provisional recommendation in §0 stands (*ready for limited production*, which is what it is). The two items flagged first — **D13** (wrong profit figure after a void) and **D10** (crash screen under simultaneous entries, which turned out to include POS checkouts crashing against supplier returns and other screens) — are now fixed locally, tested, and **not yet deployed**. The new **D14** (combined multi-branch report counts voided bills) is the next one I would ask approval for, and the cron (R1) is still the most important operational item.

Suggested order for the rest if you approve: D4+D5 → D3 → D6 → D1 → D11 (D2, D7, D8, D9, D12, D14, D15 were decided and fixed on 2026-11-28, §10). **Next QA phase:** finish Phase 4 (the §9.5 items, on MariaDB 11.8), then B-01 restore test and S-02 security probes, then the repo-owned Playwright project and CI.

---

## 10. Owner decisions of 2026-11-28 — D2, D7, D8, D12, D14 (fixed locally, **not deployed**)

Rules followed: nothing was deployed, no production data was written, no migration was run on production (the production audit session was set to `READ ONLY`). Every fix was written test-first: the failing test was shown to fail on the old code, then the fix, then the test passed. Nothing was skipped or hidden.

### 10.1 What was fixed, and why it was wrong

| ID | Owner decision | Root cause | Fix |
|---|---|---|---|
| **D14** | A voided bill is never a sale — anywhere, not only in one report | Every place that reached across shops with `withoutGlobalScopes()` also switched off `Sale`'s own "active" scope (not voided + counted in reports), or read `sale_items`/`sales` through a join with no such filter. Nine places counted voided bills (some also undelivered online orders): the combined multi-branch report, the company-dashboard branch table, its revenue-trend chart and "recent invoices", the stock-movement log (a voided bill listed as stock going out, with a blank invoice), dead stock (a voided sale made a product look "moved"), the Suppliers page sold-units/revenue, the home best-seller fallback, and the admin platform totals | New `Sale::scopeLive()` (the same two conditions) used wherever a cross-shop query needs it; `whereHas('sale')` / `whereNull('sales.voided_at')` on the joins. Live 200 + voided 500 now shows 200 |
| **D8** (+**D9**) | A sold IMEI can never be checked out again, on the server, for every branch; it is sellable again only after a return/exchange/void or an authorised stock correction | `SerialStock::sell()` returned null for an IMEI that was not in stock, and the sale went ahead — it only ever looked at the same product in the same shop. The duplicate-IMEI crash (D9) was the database's unique index firing with no validation in front of it | `SerialStock::sell()` locks the IMEI's rows across the whole business and refuses (422, names the IMEI and the invoice) an IMEI that is sold/written off, sits in another branch's stock, or belongs to another product — the whole bill rolls back. An IMEI never registered anywhere still sells (documented behaviour). `SerialStock::receive()` is all-or-nothing and is the authorised correction: re-registering a **sold** unit of the same product puts it back in stock (activity-logged); an IMEI already in stock, repeated in the list, or of another product is refused with a message (also for a brand-new product, before it is created) |
| **D12** | Money a customer paid without choosing an invoice must not vanish when the bill is voided — it becomes the customer's advance | `SaleReversal` clamped the customer's due at 0 and threw the rest away: a collection taken at the due screen without an invoice had already lowered the running due, so removing the whole bill pushed it below zero and the customer's money stayed in the drawer owned by nobody | `SaleReversal::releaseDue()`: the part below zero (and any collection tagged to the invoice) becomes `advance_balance`, activity-logged. The due screen itself is unchanged: a collection still lowers the due at once. Identity now holds in every random day: **cash + dues − advance = ledgers** |
| **D2** (+**D15**) | A refund goes back the way the customer paid | `ReturnController` debited the cash drawer for every refund; a bKash bill had credited the bank balance. Voiding after a return then guessed the split again. Also (D15, found while testing) voiding an exchange bill took its `trade_credit` tender off the bank balance as if it were bank money | Refund legs: cash/COD/trade-credit side comes off the drawer, bKash/Nagad/gateway/bank side off the bank balance, a split payment in its own proportions; the same for the "shop owes you the difference" of an exchange. Each return row records its legs (`refund_cash`, `refund_bank`, `refund_due`, adding up to `refund`); a void puts back exactly those legs (before the zero-floor, so a low balance cannot swallow part of it). Rows written before this change have no legs and are reversed exactly as before. The cashier cash report counts only `refund_cash`. `trade_credit` is skipped in the bank reversal |
| **D7** | Stock is valued at each lot's own purchase cost; card, supplier report, valuation and profit follow one logic; no artificial "supplier unknown"; no silent historical change | The Accounts card valued every piece at the size's **latest** cost while the supplier report valued lots at their own cost and parked the difference as "supplier unknown"; the Suppliers page, partner page, online-profit page and company dashboard each had their own cruder formula | One service, `App\Support\StockValuation` (read-time arithmetic only, nothing stored or migrated): per size, lots at their own cost, pieces no lot covers at the size's current cost, lots larger than the shelf scaled down pro rata. Used by the card, its detail page, the supplier report, the Suppliers page (lot shops), the company dashboard, the partner page and the online-profit page. A shop without lots takes the original single-query path, unchanged. Sale lines already snapshot the lot's cost, so profit and valuation agree: *bought at cost = sold at cost + still on the shelf* is now a test |

### 10.2 Files changed

`app/Support/StockValuation.php` (new) · `app/Support/ShopLock.php` (from D10) · `app/Support/SerialStock.php` · `app/Support/SaleReversal.php` · `app/Support/Reports.php` · `app/Support/OnlineProfitReport.php` · `app/Models/Sale.php` · `app/Models/SalesReturn.php` · `app/Http/Controllers/App/{AccountsController, ReturnController, CompanyDashboardController, StockMovementController, DeadStockController, SupplierController, HomeController, PartnerController, ProductController}.php` · `app/Http/Controllers/Admin/AnalyticsController.php` · `database/migrations/2026_11_28_000001_add_refund_legs_to_returns_table.php` (new) · tests: `tests/Feature/Phase3/{P3VoidedBillsEverywhereTest, P3ImeiSaleBlockTest, P3CustomerAdvanceTest, P3RefundChannelTest}.php` (new) and updated `P3SupplierValuationTest, P3ReconciliationTest, P3SalesMoneyTest, P3VariantBarcodeSerialTest`, `SupplierStockValueTest` · `tests/QA/concurrency/run.php` (scenarios S15, S16, S17, R1).

**Database migration needed: yes — one, additive and harmless:** three nullable decimal columns on `returns` (`refund_cash`, `refund_bank`, `refund_due`). No back-fill, no existing row touched. It must run **with** the deploy (`php artisan migrate --force`, as the normal deploy command already does): the new code writes those columns, so deploying the code without the migration would make returns fail. It has been run on the local QA database only.

### 10.3 Tests

- **Full PHP suite: 2,104 passed, 0 failed (14,158 assertions)** — 2,061 before this round, +43 new tests. The 5 reproductions of the still-open items (D1, D3, D4, D5, D6) are excluded from that run by design.
- **Parallel transactions on MariaDB (real processes): 19 scenarios × 4 rounds = 76 rounds, 0 violations, 0 server errors.** New this round: **S15** the same IMEI sold by six cashiers at once → exactly one bill, five refused; **S16** a bill voided while the customer is paying down their due → books balance in both orders; **S17** the three pieces of a bKash bill and of a cash+bKash bill returned in parallel → each refund leaves by the right door, legs add up; **R1** the company dashboard (MySQL `DATE_FORMAT`, cannot run on SQLite) — and it was proven able to fail by removing the fix.
- D14 has a test for each of the nine places (the dashboard's trend chart and recent invoices on MariaDB, scenario R1, because they use `DATE_FORMAT`); D2 a matrix (bKash, cash, split, part-credit, exchange, void after return, legacy row without legs, cashier report); D12 seven cases plus the random-day oracle now including voids; D8 twelve cases.

### 10.4 Production read-only audit (2026-11-28, one SSH session, `SET SESSION TRANSACTION READ ONLY`, SELECT only; script kept out of the repository)

| Item | What production says |
|---|---|
| **D14** — Baby Park & Liberty (#80 + #81), combined report | **Overstated by ৳19,910 this month, ৳39,990 over 30 days, ৳49,900 all time** — made of 17 voided bills (৳35,130) and 11 undelivered online orders (৳14,770). Combined profit showed ৳97,862 against a real ৳84,667 (**+৳13,195**). Single-shop reports were always right |
| **D13** — voided bills with returns | One return (refund ৳1,990, cost ৳1,300) sat on a voided bill at #80: the old profit report was **৳690 too low** |
| **D2** — bKash/Nagad/gateway refunds | **None exist** — the bug never hit a real refund, so no historical correction is needed |
| **D8** — IMEI | No paid shop has serial tracking; no IMEI on file anywhere |
| **D12** — orphaned collections | One voided credit bill (৳340) and **no** unlinked collection against it — nothing was lost |
| **D7** — SEAMAN (#157, the only lot-tracked paid shop) | Stock value on the Accounts card moves **৳460,270 → ৳467,270 (+৳7,000, +1.52%)** when deployed. Net worth and "retained profit" move by the same ৳7,000. By supplier: no supplier on record ৳162,180 (untracked pieces and lots with no supplier — genuine, not an artefact), Sujon ৳156,850, Bulu ৳57,040, Jahin Vai Dhaka ৳29,520, Garments ৳12,530, Monir garments ৳11,200 |

### 10.5 Risks and unresolved

1. **D12 interpretation.** I kept the due screen as it is (a collection lowers the due at once — Baby Park and SEAMAN work this way daily) and made the **void** turn any unattributed money into advance. If you meant that *every* payment without an invoice should be recorded as advance and only reduce the due when it is later applied, that is a different, bigger change of the due screen; say so and I will propose it separately.
2. **Pharmacy expiry batches** (`ProductBatch`) are untouched: they carry a cost, but sale lines do not snapshot it, so valuing batches at batch cost without changing checkout profit would split the costing logic. No paid shop uses batch tracking. Needs its own decision.
3. **D15 policy question.** After an exchange bill is voided the customer's trade credit is not re-issued (as before). Only the wrong bank movement was fixed.
4. **Company dashboard under a shared pool.** Each pooled branch row shows the one pooled stock figure, so the company total adds it once per branch. Seen in the code, not measured, not changed.
5. **Split refunds round per return event** (cash/bank legs to the paisa), so several returns of one split-paid bill can differ from the original tender mix by ≤ ৳0.01 each; totals are exact.
6. **Still open (not approved this round):** D1 (1 paisa rounding), D3 (Purchases screen intake ledger), D4/D5 (void and damage do not touch supplier lots — they affect SEAMAN; D7's pro-rata scaling keeps the card safe meanwhile), D6 (supplier report "sold" attribution), D11 (partly addressed: the legs now exist for new returns).
7. **Deploy order:** run the migration in the same step as the code (see §10.2). After deploy, SEAMAN's net worth steps up by ৳7,000 and the combined Baby Park report steps down by the voided/undelivered amounts above — expected, explain it to the owners.

### 10.6 Git

Committed and pushed to GitHub (`main`); **production is untouched and waiting for your explicit approval** — see the commit list at the end of this round's final message.
