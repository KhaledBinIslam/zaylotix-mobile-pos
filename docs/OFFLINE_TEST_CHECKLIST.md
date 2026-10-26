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
- [ ] Complete a sale. Confirm the receipt sheet opens showing a placeholder "OFF-XXXXXXXX" invoice number, clearly marked "অফলাইন বিল — সিঙ্কের পর চূড়ান্ত নম্বর পাবেন" (Phase 12 — this used to just toast with no receipt at all).
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

## Offline navigation, bottom nav, print/WhatsApp, and the Pending Sales sheet (Phase 12, see docs/FIX_PLAN.md)
Automated coverage: `resources/js/support/__tests__/navErrorMessage.test.js`, `offlineSaleSnapshot.test.js`, `syncModeDecision.test.js`, `offlineQueue.test.js`; `tests/Feature/OfflineSyncModeSettingTest.php`. Manual cross-checks:
- [ ] While genuinely offline, tap a sidebar/bottom-nav item that goes to a page not already cached on this device (something other than POS/Stock/Sales History). Confirm a single, clear "এই পেজ দেখতে ইন্টারনেট লাগবে..." toast appears, you stay on the current page, and NOT the old generic "কিছু একটা ভুল হয়েছে" message.
- [ ] Fully reload the app while online, landing on Home (not POS). Wait a moment for the background prefetch, then go offline and tap "Sell" WITHOUT ever having visited POS/the Tables screen this session. Confirm it opens normally instead of showing the "needs internet" message — this is the one destination that must always work offline.
- [ ] On a narrow phone (≈360px wide), check the bottom nav with each of the longer-named customizable slots selected (Sales History, Purchase History, Customer List) in both Bengali and English. Confirm every label stays on one line (ellipsis instead of wrapping) and every icon lines up at the same height — check in both light and dark mode.
- [ ] Complete an offline sale, then from the receipt sheet tap WhatsApp. Confirm (with internet actually off) it shows "ইন্টারনেট এলে পাঠানো যাবে" instead of silently failing; with internet on, confirm it opens wa.me with a message that includes the "⏳ অফলাইন বিল" note and the correct items/total.
- [ ] Open the Pending Sales sheet (via the offline banner, or POS's own 🔄 button) while a sale is still queued. Confirm Print and WhatsApp both work from there too, not just right after checkout.
- [ ] As owner, in More → অফলাইন সিঙ্ক মোড, switch to "যাচাই করে" (review). Queue an offline sale, go back online. Confirm it does NOT auto-sync — the pending banner stays up, and only tapping "সিঙ্ক করুন"/"এখনই সিঙ্ক করুন" in the sheet actually sends it. Switch back to "অটো" and confirm the 30-second countdown banner (with a "থামান" stop button) appears before it auto-sends, and that stopping it leaves the sale pending (unsent) until the next reconnect or a manual sync.
- [ ] As a cashier (no `returns` permission), queue an offline sale under a DIFFERENT staff account on the same device (or check via the UI logic) — confirm you can discard your OWN queued sale but not someone else's (shown as "এটা আপনার তৈরি বিল নয়" instead of a cancel button). As the owner, confirm you CAN discard anyone's.
- [ ] After a sale that was printed/shared as "OFF-XXXXXXXX" actually syncs, open the Pending Sales sheet's "সম্প্রতি সিঙ্ক হয়েছে" section and confirm that placeholder maps to the real INV- number.

## Session/419 — stale token on a long-open tab (Phase 13/"0.1", see docs/SESSION_FIX.md)
Automated coverage: `tests/Feature/SessionFixTest.php`, `fetchWithSessionRetry.test.js`, `sessionKeepAlive.test.js`, `offlineQueue.test.js` (`offlineSyncFailureKind`). Manual cross-checks that need real elapsed time/a real device:
- [ ] Log in, open POS, leave the tab genuinely idle (screen locked or minimized, not just sitting unfocused) for over the configured `SESSION_LIFETIME`. Come back and complete a sale. Confirm it either succeeds transparently (the keep-alive ping already refreshed things) or, if it does hit a 419, that `fetchWithSessionRetry` recovers it with no visible error at all.
- [ ] Queue an offline sale, then simulate an expired/stale token before reconnecting (e.g. via DevTools: edit the `<meta name="csrf-token">` content to garbage). Go online. Confirm the Pending Sales sheet shows it as a plain retryable entry ("সেশনের সমস্যা..." / a normal "retry" button) — never the per-item "match this product" resolution screen.
- [ ] While genuinely logged out (session expired for real, or logged out in another tab), tap something that requires login. Confirm you land on the login page, log in, and land back on the SAME page you were trying to reach — not just the generic home page.
- [ ] Open the login page, wait a few minutes (or longer), then submit. Confirm it either just works, or — if it does hit a session hiccup — shows a clear Bengali message with the phone/email field still filled in (not reset to blank).

## Stuck-bill fix-and-resync + shift duration format (see docs/FIX_PLAN.md)
Automated coverage: `resources/js/support/__tests__/offlineQueue.test.js` (`classifyOfflineFailure`, `editQueuedEntry`), `tests/Feature/OfflineSyncEditRetryTest.php`. Manual cross-checks:
- [ ] Go offline (airplane mode on phone, or DevTools → Network → Offline). Start a sale with a due amount (tendered < total) and leave the customer phone field empty. Confirm checkout is blocked client-side with "বাকি রাখতে হলে কাস্টমারের ফোন নম্বর দিন।" — it should never even reach the pending-sales queue.
- [ ] Repeat the same sale but with "সম্পূর্ণ পরিশোধ" (complimentary) mode — confirm it's allowed offline with no phone, same as online.
- [ ] Force a bill into the failed state anyway (e.g. edit the queued entry's payload via DevTools/IndexedDB to strip `customer_phone`, or trigger any other permanent 422), then open the Pending Sales sheet. Confirm the entry shows "ঠিক করুন" and, once tapped, the correct hint text for the failure (phone-needed / stock / discount / generic) plus the right fields (customer search, per-item qty/remove, "সম্পূর্ণ নগদে নেওয়া হয়েছে", "ছাড়/কুপন বাদ দিন" as applicable).
- [ ] From that panel, add a customer phone and save. Confirm it resyncs automatically and the entry disappears from the failed list (moves to "সম্প্রতি সিঙ্ক হয়েছে").
- [ ] Queue several failed entries, tap "সব সিঙ্ক করুন". Confirm every retryable entry gets re-attempted in one tap (no need to open each one individually) and entries that are still genuinely broken stay failed with their reason intact.
- [ ] Leave a bill stuck/failed for 7+ days (or fake `createdAt` in IndexedDB to simulate it). Confirm the pending-sales banner turns urgent (color/style change) and shows the stuck-days count and the failure reason, not just a generic "pending sales" label.
- [ ] Open More → staff clock-in / the shift banner, with a shift open for 24+ hours (or fake `activeWorkPeriod.opened_at` to simulate it). Confirm the duration reads like "১ দিন ৫ ঘণ্টা" (day rollover, not e.g. "920h 44m"), and the banner shows an urgency indicator at 24h+ — confirm it does NOT auto-close the shift on its own.
- [ ] Check the Pending Sales sheet's print/WhatsApp icons — confirm no duplicated emoji (e.g. "🖨️ 🖨️ প্রিন্ট") and WhatsApp consistently shows 💬, not a mix of 💬/📤.

## Price/stock visible everywhere a product is picked (see docs/FIX_PLAN.md's "price/stock missing during order/sale" fix)
Automated coverage: `tests/Feature/PosIndexPropsTest.php`, `tests/Feature/MoreControllerTest.php`, `tests/Feature/OnlineOrderCreatePropsTest.php`, `resources/js/support/__tests__/productDisplay.test.js`. Manual cross-checks (online, not offline — still belongs in this file per its existing "all POS-screen manual checks" convention):
- [ ] On the clothing/shoe POS screen (Clothing/Pos.vue), add a plain (no size/color) product with healthy stock (well above its reorder point). Confirm the card shows a normal "X in stock" line — not a blank space — same as it already does for low/out-of-stock products.
- [ ] Add a product with 2+ variants that have genuinely different prices (e.g. a shoe where one size costs more). Confirm the card's top price shows a "৳১২০০–৳১৮০০" range, not just one variant's price; confirm a same-price-everywhere variant product still shows one plain number.
- [ ] From More → ক্রয় (Purchase), open the product dropdown. Confirm each option now shows both the current price and current stock, matching the Damage/Return dropdowns just below it on the same sheet.
- [ ] From Online Business → অর্ডার তৈরি করুন (new online order), pick a plain (no variant) product. Confirm its current stock now shows next to the price in the dropdown, same as a variant option already shows its own stock.
