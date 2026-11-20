# Scalability & Infrastructure Audit (Section 8) + Server Resource Audit (Section 9, partial) + Stress Testing (Section 10)

Part of the full technical audit (section 16, deliverable #7). Read this one before the others — it sets the honesty boundary the master prompt itself demands ("Never claim that the application supports a user count that was not actually tested").

## What was NOT tested, and why — stated up front

**Zaylotix runs on a single Hostinger Cloud Professional VM with no staging environment, no load balancer, no second application server, and no load-testing tool (k6/JMeter) installed anywhere in this environment.** This is the only production server, and it is actively serving paying customers (Baby Park, SEAMAN) right now.

The master prompt's own rule 13 says: *"Do not perform production load testing without explicit authorization."* Rule 1.9 says: *"Never modify production data"* and the stress-testing section itself (10) says: *"Only execute these profiles in an isolated staging environment with synthetic data and adequate resources... Stop testing if CPU, memory, database connections, latency or error rates exceed safe thresholds."*

There is no isolated environment to run these in. Running 10-1,000 concurrent synthetic users against the single production server risks degrading or crashing the one system real customers depend on, with no safety margin and no way to "stop before it's too late" if the shared-hosting node's other tenants are also under load at that moment. This was already flagged to Khaled earlier in this audit and holds for this section too.

**Classification for every numeric load claim below: NOT TESTED.** Nothing in this document should be read as "verified to handle N users" — only as "the architecture's design, inspected from the code, does/doesn't have an obvious structural blocker to scaling."

## A. Stateless architecture readiness (code-level, VERIFIED by inspection)

| Question | Finding | Status |
|---|---|---|
| Is session storage centralized? | `config/session.php` driver is `database` (confirmed, not file/local) — sessions live in MySQL, not on local disk. A second app server reading the same DB would see the same sessions. | VERIFIED PASS (architecturally ready) |
| Is the cache store centralized? | Production's cache driver is `database` (confirmed live — `storage/logs/laravel.log` shows real `select * from cache where key in (...)` queries against a `cache` table, not file-based lookups). Centralized, same reasoning as sessions. | VERIFIED PASS (architecturally ready) |
| Are uploaded files stored centrally? | `config/filesystems.php`'s `local` disk root is `storage/app/private` — **local to this one server's disk**, not S3/object storage. Product photos, backups, and any owner-uploaded file live only on this VM. | POTENTIAL RISK — blocks true multi-server horizontal scaling (a second app server wouldn't see the first server's uploaded files) and is a single-disk point of failure. Not a problem today (one server); would need to move to S3-compatible storage (`config/filesystems.php` already supports an `s3` disk, just not configured) before adding a second app server. |
| Are background jobs safe across multiple workers? | `QUEUE_CONNECTION=database`, processed via a scheduled `queue:work --stop-when-empty --max-time=50` riding the per-minute cron tick (see `routes/console.php`) — not a persistent daemon, by host necessity (`exec`/`proc_open` disabled). Laravel's database queue driver uses row-locking (`SELECT ... FOR UPDATE`) to claim a job, so two workers pulling from the same `jobs` table concurrently wouldn't double-process — this is safe in principle. | VERIFIED PASS (architecturally ready), but see note below |
| Can a user move between servers without session issues? | Follows directly from the database session driver above — yes, architecturally, if DNS/load-balancing ever pointed two app servers at the same database. | NOT TESTED (no second server exists to actually prove this) |

**Note on the queue being "cron-tick" rather than a real daemon**: this works correctly today because job volume is near-zero (`GenerateShopBackupJob` is the only real job dispatched, per the code's own comment). If job volume grew significantly, a job could sit for up to ~60 seconds before the next cron tick picks it up, and the 50-second max-time cap means a burst of many jobs in one minute could take several cron ticks to drain. Not a bug — a scaling limit worth knowing about before relying on the queue for anything time-sensitive.

## B. Load balancing readiness

Not deployed, not tested. From the architecture (centralized session/cache, per-tenant row-scoping, no server-local state except file uploads): adding an Nginx/cloud load balancer in front of multiple app-server copies would require, at minimum, moving file storage off local disk (see table above) first. Health-check/graceful-failure behavior: **NOT TESTED** — there is only one server to test against, and deliberately taking it down to observe failure behavior would be a real outage for real customers.

## C. CDN

Static assets (`public/build/assets/*`) are served directly by this app's own Nginx, not through a CDN — confirmed via the cloud-migration notes: the root domain (`zaylotix.com`) uses a Hostinger CDN ALIAS, but `pos.zaylotix.com`'s CDN ALIAS was deliberately removed during the 2026-11-14 migration because it broke SSL (`ERR_SSL_PROTOCOL_ERROR`) — see `[[project-zaylotix-cloud-migration-2026-11-14]]` memory / prior session notes. So today, the app domain intentionally runs CDN-free. This is a real, known trade-off (simpler, SSL just works) rather than an oversight — flagging as **POTENTIAL RISK** only in the sense that static-asset load currently all hits the single origin server; not urgent at current traffic.

## D. Horizontal scaling / E. Database scaling

Both NOT TESTED for the same single-server reason. From inspection: MySQL connection handling uses Laravel's normal per-request connection (no explicit connection pooling layer, no PgBouncer-equivalent for MySQL); no read/write replica split exists or is configured (`config/database.php` has one `mysql` connection, not a `read`/`write` pair); no replication is set up. Indexing: per-table index review is in `DATABASE_AUDIT.md`, not duplicated here.

**Explicit, standing decision (per `[[project-zaylotix-scale-readiness-2026-10-26]]`): the VPS/Redis infrastructure move needed for real horizontal scaling has been deferred by Khaled's own choice and is not being re-proposed here unprompted** — this section documents the current state for the record, not as a recommendation to act now.

## E. Server resource audit (section 9) — what WAS safely checked live

Read-only checks against the current production server, no load imposed:

| Metric | Value (checked 2026-11-20) |
|---|---|
| Disk | 21T total, 15T used, 6.1T available (71% used) — shared-node figure, not this app's own quota; not a near-term concern |
| PHP memory_limit | 6144M |
| PHP max_execution_time (CLI) | 0 (unlimited) — web/FPM pool value not separately checked |
| OPcache | Enabled |
| Error log (last ~2000 lines) | No active error storm; the only multi-error clusters found are the 2026-11-14 migration-cutover transients (DB-credential/table-not-found errors during the actual server switch, already known/expected) and two of this session's own diagnostic-script artifacts (2026-11-16) — neither represents an ongoing production issue |

**Real finding, already acted on**: the nightly database backup (`zaylotix:backup`, scheduled `dailyAt('02:00')`) had **not run since 2026-11-14** (6 days) — almost certainly because cron on the new Cloud-Professional server wasn't fully active until recently (matches the pending item noted right after the 2026-11-14 migration). A backup was run manually during this audit (`zaylotix-pos-2026-11-20_114234.sql`, confirmed on disk) to close the gap immediately. **Action needed from Khaled: confirm tomorrow that a fresh `zaylotix-pos-2026-11-21_02....sql` file appears on its own** — if it doesn't, cron still isn't reliably firing this specific task and needs another look.

Crash-scenario questions from section 9 (what happens if Redis becomes unavailable, a queue worker crashes, a DB connection drops mid-transaction) are **NOT TESTED** — deliberately inducing any of these against the live production server would itself be the outage the audit is trying to prevent. From code inspection: there is no Redis in this stack at all today (cache/session/queue all use MySQL, see section A), so "Redis unavailable" isn't a live risk; a DB connection drop mid-`DB::transaction()` rolls back cleanly (standard Laravel/PDO behavior, exercised indirectly by every `lockForUpdate()` test in `DATABASE_AUDIT.md`/`INVENTORY_STOCK_AUDIT.md`).

## Section 17 scores contributed by this document

- **Scalability**: cannot be scored numerically without data this audit explicitly declined to collect unsafely. Qualitatively: architecturally ready for a second app server on the session/cache/queue axis; blocked on local file storage; completely unvalidated under real concurrent load.
- **Infrastructure stability**: no active incidents found; one real gap found and closed (backup gap); crash-scenario resilience unverified by design (see above).
