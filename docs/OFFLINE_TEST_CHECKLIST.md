# Offline / PWA Manual Test Checklist

Automated tests cover the server-side idempotency logic (`client_uuid`
dedup, see `tests/Feature/PosCheckoutTest.php` and related). The items
below need a real phone/browser and can't be scripted — run them after
any change touching `offlineQueue.js`, `useOfflineSync.js`, the service
worker, or `PosController::performCheckout()`.

## Setup
- [ ] Open the app on a phone (or Chrome DevTools device mode), log in, open POS.
- [ ] Confirm the PWA is installed/installable (Add to Home Screen prompt or already installed).

## Going offline mid-sale
- [ ] Turn on Airplane Mode (or DevTools → Network → Offline) while POS is open with items in the cart.
- [ ] Complete a sale. Confirm a "queued, sale pending" indicator appears (not a full receipt — no invoice number exists yet).
- [ ] Add another item, complete a second offline sale. Confirm it queues too.
- [ ] Close the tab/app entirely while still offline, then reopen it. Confirm both queued sales are still pending (not lost).

## Coming back online
- [ ] Turn network back on. Confirm the queued sales sync automatically (no manual action needed).
- [ ] Confirm a toast/notification appears once each queued sale finishes syncing, with a working link to the real synced sale.
- [ ] Open each synced sale and confirm stock was decremented exactly once per sale (not double-decremented) and exactly one `Sale` row exists per queued entry — check Stock page and Sales history, not just the toast.

## Replay / duplicate-submission safety
- [ ] With DevTools open, queue an offline sale, then manually resubmit the exact same queued request a second time before it syncs (simulating a flaky connection retry). Confirm only ONE sale is created server-side (idempotent on `client_uuid`), not two.

## Auto-refresh on resume
- [ ] On a phone, open the Stock page, lock the screen (or switch to another app) for 10+ seconds, then unlock/return. Confirm the page auto-refreshes with current data (no stale numbers), on each of: Pos, Stock, Restaurant Order/Tables, Kds, Cds.

## Update rollout (service worker)
- [ ] Deploy a change, then with the app already open on a device, confirm it does NOT force-reload mid-sale — the update should apply only once the tab is genuinely backgrounded/locked, never interrupting an open cart.
- [ ] Confirm a normal page load (not mid-sale) after a deploy picks up the new version without a manual hard-refresh.

## Cross-check after any offline-sync-related code change
- [ ] No duplicate `Sale` rows for any `client_uuid` (`select client_uuid, count(*) from sales group by client_uuid having count(*) > 1` should return nothing, per-shop).
- [ ] No stock drift between `products.stock` and the sum of its transactions for a shop that had offline sales during testing.

## "Product not found" / wrong-shop sync (Phase 11, see docs/FIX_PLAN.md)
Automated coverage: `tests/Feature/OfflineSyncProductResolutionTest.php`. Manual cross-checks that need a real device/admin account:
- [ ] Admin impersonates Shop A, queue an offline sale (airplane mode), stop impersonating WITHOUT letting it sync, impersonate Shop B in the same browser, go back online. Confirm: the failed-queue sheet shows a "queued for a different shop" message naming Shop A — never a generic "product not found" — and the sale is NOT silently synced into Shop B.
- [ ] From that same stuck entry, confirm "cancel this sale" (with an optional reason) removes it and the shop's Activity log gets a `pos.offlineSaleDiscarded` entry.
- [ ] Soft-delete a product while a sale for it is genuinely queued offline (not yet synced), then go online. Confirm it still syncs successfully (historical sale, allowed) — but confirm a FRESH/live attempt to sell that same now-deleted product is still rejected normally.
- [ ] Queue an offline sale with two different products, then (via DB or admin) make ONE of them unresolvable before syncing. Confirm the failed-queue sheet names that specific product, offers "match to another product" (search works, remapping fixes and auto-resyncs) and "drop item" (removes just that line, keeps the rest), without ever silently dropping the whole bill.
