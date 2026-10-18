# Changelog

Entries below cover the 2026-10 comprehensive audit-and-fix effort
(Phases 0-9, per `docs/FIX_PLAN.md`). Earlier history is in `git log`.

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
