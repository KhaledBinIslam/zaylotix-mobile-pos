# FRONTEND_UX_AUDIT.md — Frontend & User Experience Audit

Covers master-prompt section 12. This document was added after the rest of the audit (sections 1-11, 13-18, plus section 19) was already complete — section 12 is the one that doesn't map onto any of the 11 named deliverables in section 16, so it had no home until now.

**Method**: static, codebase-level analysis across all 108 `resources/js/Pages/**/*.vue` files (every page in the app, not a sample) for the two highest-certainty, most mechanically verifiable checks, plus targeted spot-checks for the rest. Classification key, same as the sibling documents:

- **VERIFIED PASS** — checked by a real script/grep against the actual code, result confirmed by hand.
- **VERIFIED FAIL** — a confirmed, reproducible bug.
- **NOT TESTED** — not checked in this pass.
- **BLOCKED BY MISSING ACCESS** — section 12 literally cannot be done this way; stated plainly below.

---

## 1. Undefined click-handler bug class (the exact class that caused the real `Clothing/Pos.vue` dead-checkout-button bug fixed earlier this session)

**Method**: a script parsed every page's `<script setup>` block for every declared/imported/destructured name, and every `<template>` event-binding attribute (`@click`, `@submit`, `@change`, `v-on:*`) for the root identifier of each handler expression (call-expression roots and bare-identifier method-handlers), then flagged any handler identifier with no matching declaration anywhere in that file.

**Result: VERIFIED PASS across all 108 pages, 345 distinct handler call-sites examined.** One candidate was flagged (`encodeURIComponent` in `Clothing/Pos.vue:684,912`) and manually confirmed to be a JS built-in global, not a bug — zero genuine findings. No second instance of the `dueNeedsCustomerPhone`-class bug exists anywhere else in the page tree today.

## 2. Broken route() references

**Method**: a script built the full registered-route-name set from `routes/web.php` (correctly tracking the `app.`-prefixed group, lines 158-783, versus the unprefixed routes outside it) and `routes/admin.php` (`admin.`-prefixed, applied in `bootstrap/app.php`), then cross-checked every `route('...')` call across **all 125 `.vue` files** under `resources/js` (Pages, Components and Layouts — broader than just Pages, since a broken route in a shared component would affect every page using it).

**Result: VERIFIED PASS. Zero broken route references.** 416 registered route names; 512 call sites across 376 distinct names referenced from Vue; every single one resolves. No dead link / broken-route bug exists anywhere in the current frontend.

## 3. Sheet/modal state-duplication bug class (the `cartOpen`/`checkoutConfirmOpen` class already fixed this session)

**Method**: found every page with 2+ `const *Open = ref(false)` declarations (the shape of the already-fixed bug), then manually traced how each pair/set of refs actually interacts.

**Result: VERIFIED PASS.** Four candidate pages found:
- `Pos/Index.vue`, `Clothing/Pos.vue` — the two pages already fixed this session for exactly this bug; confirmed the fix (`cartOpen`/`checkoutConfirmOpen` reopening correctly on every error/cancel path) is still in place.
- `Stock/Index.vue` (5 open-refs: `scannerOpen`, `smartScanOpen`, `gridOpen`, `osOpen`, `bulkOpen`) — traced each one's open/close call sites; these are independent, non-competing sheets/sections (a barcode scanner, a smart-scan sheet, an inline opening-stock grid, an opening-stock sheet, a bulk-import sheet) — none of them is supposed to reopen another on close, so there's no instance of the bug's actual shape (two sheets sharing state where closing one should reopen the other but doesn't).
- `OnlineBusiness/Orders/Create.vue` (2 open-refs: `scanVariantSheetOpen`, `cameraOpen`) — a camera-scan sheet and a variant-picker sheet used sequentially (scan → detect → pick variant), not a competing pair.

No new instance of this bug class found.

## 4. Forms without visible validation-error display

**Spot-check, not exhaustive** (per the master prompt's own "spot-check" framing for this item). 42 `.vue` files render `form.errors.*` somewhere in their template. Spot-checked `Admin/Shops/Edit.vue`, `Sales/Show.vue`'s void sheet, and `Purchases/Index.vue` directly — each renders the specific field-level error block (`<div v-if="form.errors.X">{{ form.errors.X }}</div>`) for the fields that actually carry server-side validation rules. **VERIFIED PASS on the sample checked; NOT TESTED beyond that sample** — a genuine exhaustive per-field cross-reference across all 42 files against their matching FormRequest rules would be its own multi-hour pass.

## 5. Async-action buttons without a processing/loading guard

**Spot-checked the highest-stakes examples directly**: POS checkout (`Pos/Index.vue:2346`) — `:disabled="submitting"`; sale void (`Sales/Show.vue:317`) — `:disabled="hasReturn || !voidReasonValid || voidSubmitting"`. Both money-moving/destructive actions this session's own work already hardened (client_uuid idempotency server-side) also have the client-side double-submit guard as the first line of defense. **VERIFIED PASS on the two highest-value spot-checks; NOT TESTED across every other async button in the app.**

## 6. Empty-state handling

**Spot-checked** `Customers/Index.vue`, `Suppliers/Index.vue`, `Sales/Index.vue` — all three use the same deliberate pattern: `<div v-if="!items.length" class="empty"><div class="big">{emoji}</div>{{ translated message }}</div>`. Consistent, intentional, not a blank-area or template-crash risk on the sample checked. **VERIFIED PASS on the sample; NOT TESTED exhaustively across all list pages.**

---

## 7. Real browser verification (added after Khaled asked directly — "browser open kore test kore nao")

No browser-automation tool was pre-connected in this environment, but one could be installed on demand: Playwright + headless Chromium (`npm install --no-save playwright` + `npx playwright install chromium`, not persisted to `package.json` — removed again after this check, so the project's own dependency tree is unaffected).

**Method**: a real headless Chromium session against the LIVE production site (`https://pos.zaylotix.com`), logged in as the Standard demo shop (a non-paying demo account — no real customer data touched), mobile viewport (390×844, matching this app's mobile-first design). Console errors, uncaught page errors, and failed network requests were all captured listener-side (not inferred), plus a full-page screenshot at each stop, visually reviewed.

**Pages visited, all VERIFIED PASS (200 status, correct page title, zero console/page/network errors, screenshot visually confirmed correctly rendered, not blank)**:
- `/login` — renders correctly logged-out.
- Login submit → redirected to `/app/pos` (this shop's configured default landing page).
- `/app/home` — dashboard, stat tiles and quick-action grid all correct, "Home" tab correctly shows active-state styling.
- `/app/pos` — Sell screen, product grid, the HowToHint banner, bottom nav all correct.
- `/app/returns` — the Return/Exchange page fixed earlier this session — loads cleanly, search field present, no leftover error state.
- `/app/stock` — Stock & Products, stat tiles, Add Product/CSV Import buttons, HowToHint banner all correct.

**One visual question resolved by reading the code, not left ambiguous**: the bottom-nav "Sell" button looks the same prominent orange in every screenshot regardless of which page is actually open. Checked `AppLayout.vue` (`class="tab sell"` with its own `<span class="fab">` wrapper) — this is a deliberate floating-action-button style, permanently prominent by design, not an active-tab indicator bug; the real active-tab indicator (confirmed visually on `/app/home` and `/app/stock`, where "Home"/"Stock" correctly show the lighter highlighted state) works correctly and independently of the Sell button's own styling.

**Honest scope of this real-browser check**: 6 page-loads, 1 shop (Standard, the `clothing` business type), 1 viewport, desktop headless Chromium standing in for a real device — not the full "desktop/mobile/tablet/Chrome/Android, every business type" matrix section 12 describes, and no actual data-mutating action (checkout, a return, a stock edit) was performed, only navigation/page-load. It is real evidence, not an inference, for exactly the pages and actions it covered — and it found nothing wrong.

## What section 12 still couldn't fully cover

- Other business types' distinct UI (restaurant/KDS, service/repair, wholesale, Online Business) — not visited this pass.
- Tablet-sized viewports, and real Android/iOS native browser quirks (the above used desktop headless Chromium at a phone-sized viewport, not an actual device).
- Any data-mutating interactive flow (completing a real sale, a return, editing stock) — only page-loads were checked, not full click-through-to-submit journeys.
- A systematic sweep of every page in the app this way (108 pages) — section 1-6 above already closed the two highest-value bug classes (dead handlers, dead links) exhaustively via static analysis; this real-browser pass is a trust-building sample on top of that, not a second exhaustive pass.

If Khaled wants the remaining gap closed, the next increment would be the same tool (now proven to work in this environment) pointed at 2-3 more business types' key screens, or a real data-mutating journey (e.g. a full demo-shop checkout) rather than just page-loads.
