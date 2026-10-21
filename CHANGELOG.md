# Changelog

Entries below cover the 2026-10 comprehensive audit-and-fix effort
(Phases 0-12, per `docs/FIX_PLAN.md`). Earlier history is in `git log`.

## Phase 30 part C: পাইকারি ইনভয়েস নম্বরিং (separate invoice series) — Phase 30 complete
- A wholesale sale now gets its own `WS-` invoice series under the
  fuller wholesale-tiers feature, kept completely separate from the
  normal `INV-` series (never collides with it). A retail sale, or a
  wholesale sale on a shop with only the basic wholesale-pricing
  feature, both keep the exact `INV-` numbering they always had. This
  closes out the full Wholesale system (tiered pricing + MOQ, customer
  groups, separate invoices) — the last item from the Phase 4 checklist.

## Phase 30 part B: পাইকারি কাস্টমার গ্রুপ (wholesale customer groups)
- A customer can now be flagged "wholesale" (toggle on the Due/Customers
  page). Attaching a flagged customer at POS auto-defaults the sale to
  wholesale mode as a convenience — the cashier can still switch it back
  to retail for that one sale. Doesn't change how wholesale pricing
  itself is calculated, only what the toggle defaults to.

## Phase 30 part A: পাইকারি পরিমাণ-ভিত্তিক মূল্য ও MOQ (wholesale tiers)
- New opt-in `wholesale_tiers` feature (requires `wholesale_pricing`
  already on). Set several quantity-break prices per product (e.g.
  10+ units at one rate, 50+ at a lower rate) and a minimum order
  quantity POS enforces as a hard gate on wholesale checkout — a line
  below it is rejected outright, never silently sold at retail. A
  product with no tiers/MOQ set behaves exactly as the existing flat
  `wholesale_price` always has. Syncs to branches the same way
  `wholesale_price` already does.

## Fix: admin impersonation bounced to login on an inactive shop
- Likely cause of a live report: impersonating a demo/inactive shop
  from Admin tripped the normal "shop subscription inactive" check on
  every subsequent click, logging the admin's impersonated session out
  and redirecting to login. An impersonated session now bypasses that
  check entirely, same as it already bypasses every permission gate.
  Added temporary diagnostic logging in case this isn't the full story.

## Fix: feature description column too short for MySQL
- Real deploy-time failure: `features.description` was a `VARCHAR(255)`
  and three new Phase 4 feature descriptions ran past that, caught
  only on the real production deploy (sqlite, what tests run on,
  never enforces this). Widened to `TEXT` so no future description can
  ever hit this limit again.

## Phase 4 item #3: ব্রাঞ্চ-জুড়ে প্রোমোশন পুলিং (cross-branch promotions)
- New: an opt-in `shared_promotions` feature (grant to a main shop).
  A coupon-code promotion created on the main shop now syncs to every
  branch automatically — one chain-wide discount code instead of
  recreating it per branch — while each branch keeps tracking its own
  usage count independently. A branch can locally pause/resume its own
  copy but not edit or delete it. BOGO/combo promotions stay
  shop-local, unaffected.
- (Research note: branch price overrides, the other half of this gap
  report item, turned out to already be possible via the existing
  `discount_price` field, which Phase 4 item #1 deliberately kept
  branch-editable — no new field was needed.)

## Phase 4 item #2: real PO/GRN (পারচেজ অর্ডার)
- New: an opt-in `purchase_orders` feature. A new "পারচেজ অর্ডার" screen
  lets the owner place a multi-item order against a supplier (one PO,
  many lines) and receive it in parts over time — each line tracks
  how much has arrived so far versus how much was ordered, with
  stock and supplier-payable (or cash/bank) updated proportionally on
  every receipt. Coexists with the existing single-item Purchase flow,
  which keeps working exactly as before for a shop that doesn't need
  the full PO workflow.

## Phase 4 item #1, part B: ব্রাঞ্চ ট্রান্সফার accept/reject
- New: an opt-in `transfer_confirmation` feature (grant to a main
  shop). A plain or variant stock transfer between branches now goes
  into a pending "in transit" state — the source's stock leaves
  immediately, but the destination's stock only increases once that
  branch's owner explicitly accepts, or returns to the source if they
  reject. Batch/serial-tracked transfers always stay instant. Every
  shop without this feature keeps today's exact instant-transfer
  behavior, unchanged.

## Phase 4 item #1, part A: শেয়ার্ড ক্যাটালগ (real-time catalog sync)
- New: an opt-in `shared_catalog` feature (grant to a main shop). Once
  on, editing a product on the main shop pushes name/price/barcode/
  category/etc. to every branch instantly — no more needing to
  remember to press "ক্যাটালগ সিঙ্ক করুন". Each branch's own stock count
  is never touched. A branch's linked copy shows a note that shared
  fields are now edited from the main shop; stock/reorder
  point/discount price stay editable locally as before.

## Small fix: service ticket customer attach
- New: a walk-in service ticket with no phone given at intake can now
  have a customer attached (or corrected) any time before it's
  delivered — previously the only way to let it carry a due was to
  have captured a phone number at the very start.

## Phase 3, feature 10: অ্যাম্বাসেডর টুলস (ambassador tools)
- New: every ambassador now has an auto-generated referral code and a
  shareable signup link (visible on Admin/Ambassadors, with a one-tap
  copy button). A shop that signs up through it is automatically
  credited to that ambassador — both the one-time bonus and the
  ongoing recurring commission — with no extra admin step needed.
  (Scope note: the fuller "ambassador tools" wishlist — lead tracker,
  demo mode, pitch calculator, seasonal offers — was confirmed with
  Khaled as a separate, larger decision since it needs ambassadors to
  have their own login; deferred for now.)

## Phase 3, feature 9: ধরন-ভিত্তিক সুবিধা (business-type-specific features)
- New: an opt-in `generic_substitute_suggestions` feature (pharmacy) —
  an out-of-stock medicine's tile in POS now shows an in-stock
  alternative that shares its generic name, one tap to add it instead.
- New: an opt-in `warranty_expiry_alerts` feature (mobile/electronics)
  — the owner gets notified as a sold unit's IMEI warranty approaches
  expiry (30/7/0-day tiers), in time to reach out for a service or
  extended-warranty upsell.

## Phase 3, feature 8: ক্রেডিট লিমিট (customer credit limit)
- New: an opt-in `credit_limit` feature. A shop-wide default ceiling
  (Accounts page) on how much due one customer can carry, with an
  optional per-customer override (a new 💳 quick-edit button on the Due
  page). Checkout warns the cashier up front (current due vs. limit,
  shown as soon as the customer resolves) and hard-blocks with a clear
  message if a new credit sale would push the customer past it.

## Phase 3, feature 7: ডিজিটাল ক্যাটালগ লিঙ্ক (digital catalog link)
- New: an opt-in `digital_catalog` feature. A new "ডিজিটাল ক্যাটালগ" page
  shows a public, no-login link the owner can share on WhatsApp/Facebook
  — customers see whichever products the owner opted in (name, photo,
  price, in-stock/out-of-stock), with a one-tap "WhatsApp-এ অর্ডার"
  button per product and a general one at the top. Cost price,
  wholesale price, and the exact stock count are never sent to this
  public page — only an in-stock/out-of-stock flag.

## Phase 3, feature 6: রেফারেল (customer-to-customer referral)
- New: an opt-in `customer_referrals` feature. When adding a customer,
  the owner/cashier can now search-and-pick "কে রেফার করেছে" (who
  referred them, by name — Customers/Index.vue's add-customer sheet).
  Once that new customer's FIRST-EVER purchase completes, the referrer
  is automatically credited `referral_bonus_points` loyalty points
  (settable in Promotions/Index.vue's loyalty card, default 50) — a
  one-time reward per referral, never repeated on later purchases, and
  never payable for a self-referral (structurally impossible — see
  `docs/FIX_PLAN.md` Phase 20).

## Phase 3, feature 5: অ্যাডভান্স বুকিং/লে-অ্যাওয়ে (advance booking/layaway)
- New: the existing installment-plan flow (Sales/Show.vue, right after
  a sale with leftover due) now has a "📦 এটা একটা অ্যাডভান্স বুকিং"
  checkbox — same mechanism (a down payment now, stock already held
  since the sale already happened, the rest tracked on a schedule),
  just tagged as a booking and simplified to one lump payment by
  default instead of a multi-month schedule.
- New: a "📦 বুকিং (পিকআপ বাকি)" tab on the Installments page lists
  bookings still sitting at the shop, with a "পণ্য ডেলিভারি হয়েছে"
  button (and undo) to mark pickup — pure bookkeeping, never touches
  stock/money again, since those already moved at the original
  checkout.

## Phase 3, feature 4: দৈনিক মালিক সারাংশ (daily owner summary)
- New: a nightly (11:30pm) in-app notification summarizing today's
  sales, profit, top/slowest-moving product, how many products are
  low on stock, and total customer due — every number read from the
  existing reports/low-stock logic, nothing new computed. Opt-in
  per shop (`daily_owner_summary` feature).
- When the owner has a phone on file, the notification also shows a
  "WhatsApp-এ পাঠান" button that opens a wa.me chat with their own
  number, prefilled with the summary — zero cost, a real tap, no
  automation (same rule as the WhatsApp bulk fix).
- Never sent twice in one day; scheduled via the same
  proc_open-disabled-host-safe pattern every other scheduled job on
  this host already uses.

## Phase 3, feature 3: ডেড স্টক ক্লিয়ারেন্স (dead stock clearance) + a real pricing bug fix
- Fixed a real bug found while designing this feature, confirmed with
  Khaled: `products.discount_price` (settable in Stock/Index.vue,
  shown on barcode labels/exports) was never actually applied at
  checkout — a cashier always charged the full price regardless.
  Fixed on both the server (`PosController::performCheckout()`) and
  the POS/Clothing-POS cart math and product-tile display, so a set
  discount now really is what gets charged, and the cashier's own
  screen shows it too.
- New: a Dead Stock page lists products with no sale in the last N
  days (shop-configurable, default 60), nearest-expiry-first when
  batch tracking is on, with tied-up stock value shown per product.
  Select several, pick a % off, apply in one action — sets
  discount_price in bulk (and a one-click undo). Products with
  variants or sold-by-weight are excluded (a flat markdown doesn't
  unambiguously apply to either).
- Deliberately didn't build a bundle/combo suggestion engine — the
  existing Promotions page's BOGO/combo tool already covers that,
  linked from this page instead of duplicated.

## Phase 3, feature 2: সিজন স্টক প্ল্যানার (season stock planner)
- New: for a season, see a reorder suggestion per product based purely
  on what actually sold during the same date window last year, minus
  what's currently in stock. Review/adjust the quantities, pick a
  supplier, and turn the approved ones into real pending purchase
  orders in one action — nothing new for purchasing, these land exactly
  where every other purchase order already does.
- Deliberately excludes any product with size/color variants (its stock
  isn't on the base product, and the existing purchase-receive flow
  would reject it without a variant chosen that this planner doesn't
  collect) rather than generating a draft order guaranteed to fail.

## Phase 3, feature 1: সিজন ক্যাম্পেইন কিট (season campaign kit)
- New: bundle an existing offer/coupon + a customer segment (top
  spenders, most visits, highest due, inactive, all) + a WhatsApp
  message template under a named, reusable season ("ঈদুল ফিতর ২০২৭"),
  and launch all three together in one place — Campaigns page, under
  a new opt-in `season_campaigns` feature flag.
- Nothing new for discounts or messaging: launching activates the
  existing Promotion engine and opens the existing WhatsApp send queue.
- Extracted the customer-segment filter out of `CustomerList/Index.vue`
  into a shared `customerSegments.js` so both pages use the exact same
  logic instead of two copies.

## Session/419 ("Page Expired") permanent fix — see `docs/SESSION_FIX.md`
- Root cause: a prior fix (Aug 17) raised `config/session.php`'s
  SESSION_LIFETIME default from 120 to 720 minutes, but never updated
  the actual `.env` file (gitignored, never touched by a deploy) — so
  the real effective session lifetime silently stayed at 120 the whole
  time. Fixed `.env`/`.env.example`; **production's `.env` needs a
  manual one-line edit** (see SESSION_FIX.md's deploy notes) — safe, no
  forced logout.
- Every raw-fetch call site (POS/Clothing checkout, offline-sync replay)
  built its CSRF header from a `<meta>` tag captured once at page load
  and never refreshed — the real reason a POS tab left open a full
  shift could hit an unrecoverable 419. New `GET /app/csrf-token` +
  `fetchWithSessionRetry.js` now refreshes it and retries once; a new
  `sessionKeepAlive.js` proactively refreshes it every 10 minutes (and
  on tab-focus/reconnect) so this is rarely even needed in practice.
- `Clothing/Pos.vue`'s checkout/gateway-checkout fetches now go through
  `fetchWithSessionRetry` too (parity gap — `Pos/Index.vue`,
  `Restaurant/Order.vue`, `Returns/Index.vue` already had it).
- `useOfflineSync.js`'s `trySync()` used a bare `fetch()` (no retry
  protection at all) and classified a 401/419 as `'permanent'` —
  believed to be the real cause of the earlier unexplained "1 sale(s)
  failed to sync" report with nothing in the server log. Now uses
  `fetchWithSessionRetry` and a new `offlineSyncFailureKind()` helper
  correctly marks it `'transient'`: the sale stays queued and retryable,
  never routed into the per-item "match this product" resolution screen
  over something that had nothing to do with the sale's contents.
- A genuinely logged-out visit now remembers where you were
  (`EnsureShopUser`'s `redirect()->guest()`) and returns you there after
  login, instead of just the generic home page.

## Offline sync failure diagnostics
- Reported live: "1 sale(s) failed to sync" with nothing matching in
  `storage/logs/laravel.log` — a deliberate validation/business rejection
  (stock gone, a 422, a shop mismatch) was never logged the way a real
  crash is, so there was no trace to diagnose it from. Added targeted
  `Log::warning()` calls, only for an offline-sync replay (never a live
  checkout — the cashier already sees that immediately): on a shop
  mismatch, on the request failing its own validation, and on anything
  `performCheckout()` itself rejects. Purely additive — no behavior,
  response, or status code changed, only logging. The next occurrence of
  this bug will leave a real log line to read instead of a dead end.

## Bulk WhatsApp queue: match the originally-planned confirm UX, dedupe the two pages
- The queue's own plan (`docs/WHATSAPP_BULK_PLAN.md`) always called for
  marking a customer "sent" on returning from WhatsApp (`visibilitychange`),
  not the instant the button is tapped — the actual first implementation
  deviated from this. Fixed: marking now waits for the tab to become
  visible again (the real phone case — wa.me hands off to the WhatsApp
  app entirely), with a manual "✓ পাঠানো হয়েছে" button for desktop (where
  `window.open()` opens a new tab and this one never leaves "visible"),
  and an "আগের জনকে ফিরিয়ে আনুন" undo in case either path marks the wrong
  one.
- `CustomerList/Index.vue` and `Customers/Index.vue` had this entire
  queue copy-pasted — extracted into a shared
  `resources/js/composables/useWhatsappSendQueue.js` so the two pages
  can't drift apart again.
- Spam-pacing hint now explicitly says "দিনে ২০০ জনের কম রাখুন" to match
  Khaled's own stated guidance, not just a generic "pace yourself".

## Bulk WhatsApp messaging fix (see `docs/WHATSAPP_BULK_PLAN.md`)
- Fixed a real bug: selecting multiple customers and sending a free
  (wa.me) WhatsApp message only ever opened the first person's chat —
  every other `window.open()` was silently popup-blocked, because the
  old code fired them from a staggered `setTimeout` loop, which loses
  the "real user tap" status a browser/TWA requires before it'll allow
  a new window. Fixed in both places this pattern existed
  (`CustomerList/Index.vue`'s bulk send and `Customers/Index.vue`'s due
  "অফার পাঠান"): replaced with a "পরবর্তী জনকে পাঠান" (send to next) queue
  — exactly one `window.open()` per direct tap, resumable across app
  restarts, with a progress bar and skip/stop.
- New: message templates now support `{name}`, `{due}`, `{shop}`
  variables instead of a hardcoded "প্রিয় {name}," prefix.
- New: invalid phone numbers are skipped automatically (shown as a count,
  not silently dropped), duplicate phone numbers across two customer
  records are sent to only once, and a non-blocking reminder appears
  every 30 sends about WhatsApp's own anti-spam number blocking.
- New: a `.vcf` contact-card export (`customer-list/export?format=vcf`)
  so selected customers can be imported into the phone's Contacts app in
  one action and messaged via WhatsApp's own native "New broadcast"
  feature — zero cost, no automation, fully within WhatsApp's own rules.
- New: a per-customer "মার্কেটিং মেসেজ চান না" opt-out flag (`customers.no_marketing`,
  additive migration) — excluded from the free-send queue, the `.vcf`
  export, and the existing WhatsApp Business (Meta Cloud API) bulk send.
- Documented and deliberately did NOT build: WhatsApp Business Cloud API
  automation beyond what already existed (`WhatsappBulkController`), and
  explicitly ruled out whatsapp-web.js/Baileys/headless-browser automation
  as a ToS violation with real number-ban risk — see the plan doc.

## Phase 12 — Offline POS: navigation, bottom-nav labels, print/WhatsApp, Pending Sales review
- Fixed a confusing generic error toast on a failed offline navigation —
  now shows a clear "this page needs internet, keep selling" message and
  correctly stays on the current page.
- Fixed bottom-nav labels wrapping to two lines / misaligning icons on a
  customized slot with a long name (Sales History, Purchase History,
  etc.) — added short label variants and proper text-overflow handling.
- Print and WhatsApp now actually work right after an offline sale (they
  were completely unreachable before — the receipt sheet never opened),
  clearly labeled as a provisional "OFF-XXXXXXXX" bill until it syncs.
  Also fixed: `Clothing/Pos.vue`'s offline checkout had no idempotency
  key or shop-pin protection at all — a real, independently-found gap,
  now at parity with the main POS screen.
- New: an owner-controlled "review before sync" mode (shop setting,
  default unchanged/automatic) plus a real Pending Sales screen — review
  every still-unsynced sale (full bill detail, print/WhatsApp, sync-now/
  retry, cancel-with-reason) before or after it attempts to sync, with a
  30-second last-chance countdown even in the default auto-sync mode.
- Also fixed a real money bug found along the way: the POS payment
  screen showed a second, disconnected payment-amount entry box that
  wasn't actually read unless "split payment" mode was explicitly on — a
  cashier typing a partial amount there had the FULL total silently
  charged instead. Any past sale this affected can't be identified from
  the data alone.

## Phase 9 — UI/UX design-token consolidation (`2da6267`)
- Bridged Admin panel's Tailwind utilities onto the same CSS tokens the
  shop app uses (`tailwind.config.js` `brand-*` colors); replaced 38
  hardcoded `#7C3AED` occurrences across 25 files with the token.
- Retrofitted literal border-radius values onto the existing
  `--radius-sm/md/lg` scale where they matched exactly.
- Fixed a near-duplicate hex color (`.stat.sky`/`.pill.sky` vs.
  `--skySoft`) and two other hardcoded colors that duplicated tokens.
- Named two new shadow tiers (`--sh-md`, `--sh-lg`); added an unused-for-now
  font-size token scale (`--text-xs..xl`) for future pages.
- Logic unchanged — pure CSS/class-level change.

## Phase 8 — Mobile bottom-nav customization (`c8f7042`)
- Shop-level `mobile_nav_items` setting lets each shop pick its own two
  flexible bottom-nav slots (previously hardcoded to Stock + Due) from an
  11-item whitelist; defaults preserve old behavior exactly.
- Compacted the mobile header (logo/bell/lang/logout sizing).

## Phase 7 — Payroll / installments / wholesale / CRM (`cf70efe`)
- Fixed a real bug: installment plans had no upper bound against actual
  due, allowing an installment total above what was owed. Capped and
  race-guarded with a unique index.
- Added customer segmentation filters (top spenders, most visits,
  highest due, inactive 30+ days).
- Payroll date-tracking confirmed already correct.
- Wholesale-as-a-separate-system (tiered pricing, MOQ, dedicated invoice)
  flagged as an open scope question — not built.

## Phase 6 — Quotation strengthening (`67f6df0`)
- Quotation expiry (`valid_until`) now actually enforced at checkout.
- Quoted price capped server-side (removed a client-side workaround that
  would have double-discounted).
- Stock-insufficient-at-creation now warns instead of silently allowing.
- Added quotation edit (previously cancel-and-recreate only).

## Phase 5 — Service/repair module (`f0cf876`)
- Fixed: a delivered service ticket's own billed items showed empty on
  its own page (an overly-strict query excluded them).
- Status workflow, billing math, parts-stock timing, permissions, and
  cancel/void all confirmed already correct.

## Phase 4 — Supplier / purchase management (`3ba1352`)
- Free-text supplier name now creates a real `Supplier` record (was
  previously untracked, including its payable balance).
- Added new-product creation from the purchase form.
- Added a per-supplier ledger page, backed by a new `SupplierPayment`
  table (settlements previously only wrote a log line, not a queryable
  row).

## Phase 3 — Customer due/ledger integrity (`149d3b4`, `65141ea`)
- Fixed a real bug: exchange sales' trade-in credit wasn't recorded as a
  payment, overstating the customer's due by the credit amount on every
  memo/WhatsApp share and risking a bogus installment plan.
- Added optional per-invoice due tagging (`payments.sale_id`), so new
  payments can optionally record which specific invoice they're against.
- Added `due:reconcile` (read-only) to detect negative-due or broken
  payment-ledger chains.

## Phase 2 — Billing popup correctness (`f2c1317`)
- Added per-item remove button, customer search-by-name.
- Fixed a real bug: changing the customer phone field didn't reset the
  customer name, risking overwriting an unrelated customer's name.
- New server-side `BillingPreview` service — the client's displayed
  total during coupon/loyalty entry now always matches what checkout
  will actually charge (removes a false "payment exceeds total" error).
- Added a one-tap "add free item" suggestion for BOGO promotions.

## Phase 1 — Urgent stability fixes (`130269b`)
- Fixed a real bug: stock-in on a just-soft-deleted product crashed with
  a raw 500 instead of a friendly error.
- Added checkout idempotency (`client_uuid`) so a replayed offline-sync
  request can never double-sell or double-decrement stock.
- Added auto-refresh-on-resume to Pos, Stock, Restaurant Order/Tables,
  Kds, Cds.
- Added an offline-sync-complete toast.

## Phase 0 — Shop provisioning reliability (`852f9b0`)
- Fixed a race condition that could create duplicate shops on simultaneous
  signups.
- Added a safety-net fallback so a business type with no matching config
  profile no longer silently provisions a shop with zero
  categories/units/features.
- Added `shop:smoke-test` and `shops:repair-defaults` commands.
- Extended `weight_unit` to support loose-selling by meter (hardware/textile).
