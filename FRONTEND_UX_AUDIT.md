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

## What section 12 asks for that this document could NOT cover — BLOCKED BY MISSING ACCESS

Section 12 explicitly asks for testing across "Desktop. Mobile. Tablet. Chrome. Android mobile browsers... Verify that every visible button and action actually works... Identify broken links, JavaScript errors, console errors and frontend/backend mismatches" — i.e. real, interactive, rendered-browser testing. **This environment has no connected browser-automation tool** (no Playwright/Puppeteer/Selenium session, no device lab) for this audit to drive a real browser with. Every finding above is static-code-level — it proves the code is internally consistent (no dead handler, no dead link, guards present, empty states exist), but it cannot observe:

- Actual rendered layout/overflow/wrapping issues on a specific viewport size.
- Real JavaScript console errors/warnings during live use.
- Cross-browser rendering differences (the two already-fixed bugs this session were BOTH found via Khaled's own live use reports, not static analysis — static analysis is a real, useful safety net but not a substitute for a human or an automated browser actually clicking through the app).
- Touch-interaction-specific issues (tap targets, scroll behavior) that only manifest on a real mobile device.

**This is the honest limit of what was done here** — it closes the specific "dead button from a typo/missing function" and "dead link from a typo'd route name" bug classes completely (zero found across the entire page tree), which is real, meaningful coverage, but it is not a substitute for the actual device/browser walkthrough section 12 describes. If Khaled wants that specific coverage, it needs either manual QA time or a browser-automation tool connected to this environment.
