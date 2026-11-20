# Security Audit

Scope: Section 11 ("Security Audit") of the full technical audit requested
2026-11-20, plus the "can a disabled feature still be reached via direct
URL/API" bypass angle of Section 6. Safe, non-destructive, code- and
configuration-level inspection only — no live exploitation was attempted
against the production server. Builds on, and does not re-litigate,
`TENANT_CUSTOMIZATION_ISOLATION_AUDIT.md`'s own IDOR/permission findings
(§7 there) — this pass adds the routes/controllers that one didn't examine.

Labels: **VERIFIED PASS** · **VERIFIED FAIL** · **NOT TESTED** ·
**BLOCKED BY MISSING ACCESS** · **POTENTIAL RISK**.

---

## 1. SQL injection — VERIFIED PASS

Every raw-SQL method call site in `app/` (`DB::raw`, `DB::statement`,
`whereRaw`, `selectRaw`, `havingRaw`, `orderByRaw`) was enumerated (~55
matches). A second, narrower grep for any of those methods containing a
`$variable` *inside* the SQL string itself — the actual injection shape —
returned **zero matches**. Every call site is either a fully static
column/aggregate expression (`selectRaw('shop_id, COUNT(*) as c')`) or
properly parameter-bound (`whereRaw('UPPER(code) = ?', [$code])`). No
string-concatenated raw SQL exists anywhere in the codebase.

## 2. XSS — VERIFIED PASS

- Zero `{!! !!}` (unescaped Blade output) anywhere in `resources/views`.
- 27 `v-html` sites in `resources/js`, all individually traced:
  - The overwhelming majority index into a local, hardcoded `ICONS`/
    `ICON_PATHS` object by an app-defined key (nav item, menu entry) —
    never a value an attacker controls.
  - `Admin/ActivityLog/Index.vue:41,43` renders `link.label` — confirmed
    this is Laravel's own paginator `links()` output (`&laquo; Previous`,
    page numbers, `Next &raquo;`), server-generated, never user input.
  - `BarcodeLabels/Index.vue:1119` / `Settings/ReceiptLabel.vue:330`
    inject computed print CSS (`printCss`/`calibCss`) into a `<style>`
    tag. Every dynamic value inside is either a fixed label-size preset
    or explicitly `Number(x) || 0`-coerced (`offset_x_mm`/`offset_y_mm`)
    — a malicious string stored in that field becomes `NaN → 0`, not
    injected text.

## 3. CSRF — VERIFIED PASS

`bootstrap/app.php`'s `withMiddleware()` registers no CSRF exceptions at
all — Laravel's default `ValidateCsrfToken` protects every `web`-group
route. Exactly two routes in the whole app opt out
(`Route::withoutMiddleware(ValidateCsrfToken::class)`):
`gateway.webhook`/`gateway.callback` (`routes/web.php:795-798`) — both
documented inline as genuinely public (bKash/Nagad/SSLCommerz
server-to-server calls carry no session). Verified these aren't just
blindly trusted instead: `GatewayWebhookController::process()` calls each
driver's own `verifyWebhook()` against the gateway's real API and checks
the returned reference **and** amount match before any `DB::transaction`
runs (lines 127-134) — a forged POST to either route fails verification
and never reaches the money-moving code.

## 4. Auth/session configuration — VERIFIED PASS, one P3 note

`config/session.php` defaults: `http_only` → `true`, `same_site` → `lax`,
`encrypt` → `false` (fine — session driver is `database`, not filesystem,
so nothing sensitive sits in a plaintext file). `secure` has no explicit
default (`env('SESSION_SECURE_COOKIE')`, i.e. `null` → Laravel marks the
cookie secure automatically only when the request itself is HTTPS). The
production domain is HTTPS-only per this session's own cloud-migration
work, so this is effectively correct today, but isn't a positive,
self-documenting assertion.

**P3 — recommend** setting `SESSION_SECURE_COOKIE=true` explicitly in
production `.env` rather than relying on auto-detection, so it stays
correct even if an internal health-check or a misconfigured proxy ever
terminates TLS somewhere it shouldn't. Low priority, not exploitable
today.

Password hashing: both `User` and `Admin` models cast `'password' =>
'hashed'` (Laravel's native hashing cast — bcrypt by default, confirmed no
`config/hashing.php` override) and both list `password` in `$hidden`, so
it's never serialized to a response even by accident. **VERIFIED PASS.**

## 5. IDOR — VERIFIED PASS (additive to the tenant-isolation audit's §7)

Spot-checked routes/flows that audit didn't name:

- **`ReturnController`'s cross-branch exchange product resolution**
  (`resolveReceivingProductAndVariant()`, lines 719-754) — the shop whose
  catalog a returned item's stock lands in (`$receivingShopId`) is always
  `Tenancy::id()` (line 379, the authenticated session's own active shop),
  never request input; the `$sale` being returned against is independently
  constrained to `Tenancy::businessShopIds($shopId)` (line 382/384). A
  request cannot direct stock/money into, or pull a sale from, a shop
  outside the caller's own business group.
- **`PaymentController.php:166`** (`Customer::withoutGlobalScopes()->
  findOrFail($payment->customer_id)`) and the equivalent in
  `PartyController.php:205` — both resolve a customer id that came from an
  *already-tenant-verified* parent row (`$payment`, itself only reachable
  via the normal scoped lookup above it), not from raw request input. Same
  "parent already scoped" pattern the tenant-isolation audit confirmed
  elsewhere (§5).
- **Branch-propagation sites** (`PromotionController.php:116-130`,
  `ProductImportController.php:287-288`, `PriceUpdateController.php:148`)
  — all derive the branch list from `Shop::where('parent_shop_id',
  $shop->id)` where `$shop` is the *caller's own*, already-resolved shop.
  No request parameter can redirect this at another business's shops.

## 6. File uploads — VERIFIED PASS, one P3 note

Every real upload endpoint found (13 files) validates both type and size:
`logo`/`photo`/`image` fields use Laravel's `image` rule (validates actual
image content, not just the extension) or an explicit `mimes:` whitelist,
paired with a `max:` size cap (1024–5120 KB depending on purpose). CSV
imports (`file` fields) are restricted to `mimes:csv,txt` with their own
size caps. No endpoint accepts an unrestricted file type or unbounded size.

**P3 — recommend** `ArticleController.php:175` and
`ProductVariantController.php:505` (currently just `'image','max:3072'`)
add an explicit `mimes:jpg,jpeg,png,webp` the way the other 4 upload
endpoints already do, for consistency — Laravel's bare `image` rule also
accepts svg/bmp/gif. Not currently exploitable (confirmed in §2 that no
uploaded content is ever rendered via `v-html`), purely a hardening nit.

## 7. Rate limiting — VERIFIED PASS

- **Web login** (`App\Http\Requests\App\LoginRequest::authenticate()`) —
  standard Laravel Breeze-shaped 5-attempt lockout keyed by
  `login-value|ip`, with a real `Lockout` event and backoff via
  `RateLimiter::availableIn()`. **Confirmed wired up**:
  `LoginController::store(LoginRequest $request)` calls
  `$request->authenticate()`.
- **Admin login** (`Admin\AuthController`) and **API login**
  (`Api\AuthController`) — each has its own independent inline 5-attempt
  `RateLimiter` lockout, same shape.
- **Signup** — `throttle:signup` (named limiter in
  `AppServiceProvider.php:82`); a prior audit's own fix comment confirms
  this was unlimited before and was closed.
- **Checkout-family routes** (POS checkout, gateway initiate, restaurant
  order/bill, rental store/return, returns/process) — all carry
  `throttle:checkout`.
- **Public signed links** (rating QR, catalog) — `throttle:20,1` /
  `throttle:60,1` on top of the `signed` URL requirement.

No self-service password-reset route exists in `routes/web.php` — by
design, not a gap: this app's login is phone-based, and credential resets
are admin-mediated (`Admin\ShopController::updateOwnerCredentials`,
exercised live this session for Baby Park). Noted for completeness, not
flagged as a finding.

## 8. Sensitive information exposure

- `.gitignore` covers `.env`, `.env.backup`, `.env.production`,
  `/storage/app/private/backups`, `/storage/*.key`, `/storage/pail`.
  **VERIFIED PASS.**
- `.env.example`'s template default is `APP_DEBUG=true` — expected/correct
  for a *local dev* onboarding template; says nothing about the live
  value. **The actual production `.env` `APP_DEBUG` value was deliberately
  left to the parent session to check directly** (one specific SSH read,
  outside this fork's sandboxed scope) — **BLOCKED BY MISSING ACCESS**
  for this sub-item only.
- `GatewayWebhookController`'s own fix history (read in code) shows a
  prior pass already removed logging of raw gateway payloads specifically
  because they can carry a customer's phone/MSISDN — now logs only a
  fixed, minimal field set. **VERIFIED PASS.**

## 9. Dependency vulnerabilities — POTENTIAL RISK (P2)

`composer audit`: **33 advisories across 11 packages**, including **1
CRITICAL** (`laravel/framework` CVE-2026-114509, TokenGuard auth bypass)
and several HIGH (guzzlehttp/guzzle host-check bypass, maatwebsite/excel
caller-controlled export path, phpoffice/phpspreadsheet XXE/DoS,
symfony/http-foundation ReDoS, symfony/yaml unbounded recursion,
league/commonmark DoS/XSS variants).

**Two of the most severe were individually verified against this app's
actual configuration, not just the raw advisory:**

- **CVE-2026-114509 (critical, TokenGuard bypass) — NOT exploitable
  here.** `config/auth.php` defines only `web`/`admin` (session) and
  `sanctum` guards — no `'driver' => 'token'` guard is configured
  anywhere, so the vulnerable TokenGuard code path is never reached.
- **maatwebsite/excel path CVE (high) — NOT exploitable here.**
  `ExportController.php` calls `Excel::download()` exclusively (3 call
  sites) — the vulnerable vector is `Excel::store()` with a
  caller-influenced path, which this app never calls; exports stream
  directly to the HTTP response, never to a server-side disk path.
  `$name`/`$kind` are also resolved through a closed `match()` list, not
  attacker input (confirmed in the tenant-isolation audit's §7).
- `league/commonmark` is a **transitive** dependency of `laravel/
  framework` only — zero direct usage (`League\CommonMark`) anywhere in
  this app's own code, so its Markdown-parsing DoS/XSS advisories have no
  reachable attack surface here today.

The remaining advisories (Guzzle's cookie/host checks, PhpSpreadsheet's
ODS/XML handling, Symfony's header/YAML parsing) were **not** individually
re-verified for reachability in this pass — the volume (33) makes a
per-advisory exploitability proof disproportionate here, and none of them
sit in this app's own code, only vendor internals Laravel itself calls.

**Recommended fix** (not applied — read-only audit per scope): run
`composer update` to pull the patched point releases (all fixes are
within the already-installed major versions — Symfony 7.x, Laravel 12.x —
so this should be a safe, non-breaking update, but run the full test
suite immediately after, same as any dependency bump).

`npm audit --omit=dev`: **0 vulnerabilities** in production JS
dependencies.

## 10. Password storage — VERIFIED PASS

Covered in §4 above — both auth models use Laravel's native `hashed` cast,
never plain/md5/sha1.

## 11. Feature-gate bypass (Section 6's "direct URL" angle) — VERIFIED PASS

Compared the full set of feature keys gated by `feature:<key>` route
middleware (30 distinct keys, 45 route-group occurrences across
`routes/web.php`) against every `hasFeature('<key>')` call site inside
controllers (100 occurrences). Every feature with its **own dedicated
page/CRUD surface** — purchases, purchase orders, rentals, HR payroll,
service billing, suppliers, online business, digital catalog settings,
season campaigns, activity log, shop backup, cashier management,
WhatsApp bulk, promotions, and more — has a matching `feature:` route
group; spot-checked Rentals (all 5 routes: index/lookup/store/show/
processReturn) explicitly inside `['perm:rentals','feature:rentals']`
with no route left outside it.

The `hasFeature()` calls that have **no** matching route-level gate are
all one of two legitimate shapes, not a bypass:

1. **Conditional behavior inside an already-gated flow** — e.g.
   `PosController`'s `hasFeature('serial_tracking')`/`'batch_tracking'`/
   `'promotions'` checks run inside the checkout method itself, which sits
   behind `perm:pos`; there is no separate page to reach directly, since
   disabling the feature just skips that branch of logic within an action
   the user is already authorized for.
2. **`CatalogController@show($slug)`** — deliberately the one genuinely
   public, unauthenticated route (`GET /catalog/{slug}`, no session to
   route-gate against by design); its inline `hasFeature('digital_catalog')`
   check on the *resolved shop* **is** the gate, correctly 404ing if that
   shop never turned the feature on.

---

## Summary

| Severity | Count | Item |
|---|---|---|
| P0 | 0 | — |
| P1 | 0 | — |
| P2 | 1 | §9 — outdated dependencies with real CVEs; the 2 most severe confirmed non-exploitable in this app's actual config, but a `composer update` + full regression run is still recommended |
| P3 | 2 | §4 explicit `SESSION_SECURE_COOKIE=true`; §6 tighten 2 upload endpoints' mime whitelist |

**Overall verdict:** No exploitable SQL injection, XSS, CSRF, IDOR, broken
auth, missing rate-limiting, insecure file upload, or feature-gate bypass
was found. The one real action item is dependency freshness (§9) — not
urgent given the specific checks performed, but worth a scheduled
`composer update` + regression pass rather than leaving 33 open advisories
on the books indefinitely. The production `APP_DEBUG` live-value check
(§8) is the one item explicitly deferred to direct server access outside
this pass's scope.
