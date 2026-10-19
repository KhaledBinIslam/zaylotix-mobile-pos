# Bulk WhatsApp Manual Test Checklist

See `docs/WHATSAPP_BULK_PLAN.md` for the root cause and chosen approach.
Automated coverage: `resources/js/support/__tests__/whatsappQueue.test.js`,
`tests/Feature/CustomerListTest.php`, `tests/Feature/WhatsappBulkTest.php`.
These need a real phone/TWA build (popup-blocking behavior varies by
browser/WebView, so this can't be fully trusted from a desktop browser).

## The "পরবর্তী জনকে পাঠান" queue (both CustomerList and due/Customers pages)
- [ ] Select 3+ customers with real phone numbers, write a message, start
  the queue. Tap "পরবর্তী জনকে পাঠান" once. Confirm exactly one WhatsApp
  chat opens (not zero, not more than one) with the message filled in,
  `{name}` replaced with that customer's actual name.
- [ ] Return to the app, tap "পরবর্তী জনকে পাঠান" again for the 2nd
  customer. Confirm it opens THAT customer's chat (not the 1st again),
  and the progress counter advanced.
- [ ] Repeat until the queue reports "done", with the correct sent count.
- [ ] Mid-queue, tap "থামুন" (stop) and close the sheet. Re-select the
  SAME customers and reopen the send sheet — confirm it resumes exactly
  where it left off (not restarted from zero).
- [ ] Select a DIFFERENT set of customers after stopping a queue early —
  confirm it starts fresh (does not try to resume the old, now-stale
  progress).
- [ ] Include one customer with an obviously invalid/empty phone number
  in the selection — confirm they're reported as skipped up front and
  never appear as a queue target.
- [ ] Select two different customer records that share the same phone
  number — confirm only one queue entry for that number (not two sends).
- [ ] Send to 30+ customers in one sitting — confirm the "৩০ জন হয়ে
  গেছে, বিরতি নিন" reminder appears at exactly 30, and again at 60.

## vCard / Broadcast export
- [ ] Select several customers, tap "Broadcast তালিকা", confirm a `.vcf`
  file downloads. Open it (or transfer to a phone) and confirm it imports
  into Contacts as separate entries with the right names/numbers.
- [ ] Confirm a customer with no phone number is silently excluded from
  the `.vcf` (not included as a blank/broken entry).

## Marketing opt-out flag
- [ ] Tap the 🔔 icon on a customer row in CustomerList — confirm it
  flips to 🔕 and persists after a page reload.
- [ ] With that customer selected alongside others, confirm they're
  excluded from: the free-send queue (shown as skipped/excluded,
  doesn't get a chat opened), the `.vcf` export, and (if WhatsApp
  Business is connected) the "WhatsApp Business" automatic bulk send.

## Existing behavior that must still work (no regressions)
- [ ] The existing "WhatsApp Business" (Meta Cloud API) method — for a
  shop with a connected WhatsApp Business credential — still sends
  automatically to everyone selected, unaffected by any of the above.
- [ ] The existing xlsx/csv export (Customer/Phone/Total Bought/Visits/
  Due) still downloads correctly and is unaffected by the opt-out flag.
- [ ] Customers/Index.vue's (due ledger) WhatsApp payment reminder button
  (`remindWA`, single customer, not bulk) still works exactly as before.
