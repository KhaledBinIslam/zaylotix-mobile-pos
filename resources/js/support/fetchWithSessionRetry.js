// A 401 from EnsureShopUser/CheckSubscriptionActive happens in MIDDLEWARE —
// strictly before any controller's own transaction starts (checkout,
// addItem, bill, etc.) — so it's the one failure mode that's always safe to
// retry: nothing was ever charged/saved, unlike a dropped connection (a
// TypeError, handled separately by each caller, deliberately NEVER retried
// here) where it's genuinely unknown whether the server already processed
// the request. One retry filters out this host's occasional transient
// session-read hiccup under its chronically high shared-hosting load — not
// an actual elapsed session timeout, root-caused live via the same class of
// issue found on the polling screens (see usePollingReload) — before ever
// telling a cashier mid-sale that they're logged out over what was really
// just one slow read.
//
// Every caller must build fresh `options` (a fresh body/headers), not reuse
// one across calls — this just re-invokes fetch(url, options) a second time
// verbatim, so it's the caller's job to make sure that's safe to repeat
// (it always is here: nothing was accepted server-side the first time).
export async function fetchWithSessionRetry(url, options) {
    let res = await fetch(url, options);
    if (res.status === 401) {
        await new Promise((resolve) => setTimeout(resolve, 800));
        res = await fetch(url, options);
    }
    return res;
}
