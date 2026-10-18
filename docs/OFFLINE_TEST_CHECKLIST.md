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
