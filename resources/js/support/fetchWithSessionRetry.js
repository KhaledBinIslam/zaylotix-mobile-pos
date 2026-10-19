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
// A 419 (TokenMismatchException) is a DIFFERENT failure from the same root
// cause family — see docs/SESSION_FIX.md. Every caller here builds its
// X-CSRF-TOKEN header from the page's <meta name="csrf-token">, captured
// once at the moment the document first loaded and never refreshed again.
// A tab left open for hours (the normal case for a POS screen) can easily
// outlive that — the session's real token rotates, or the session itself
// gets swept and silently recreated — while the stale meta tag has no way
// to know. Unlike the 401 case, simply repeating the exact same request
// would fail identically forever, so this fetches a fresh token first (see
// CsrfTokenController), patches both the DOM's own meta tag (so every
// LATER raw-fetch call benefits too, not just this one) and this retry's
// own header, then retries exactly once.
//
// Every caller must build fresh `options` (a fresh body/headers), not reuse
// one across calls — this just re-invokes fetch(url, options) a second time
// verbatim, so it's the caller's job to make sure that's safe to repeat
// (it always is here: nothing was accepted server-side the first time).
export async function refreshCsrfToken() {
    try {
        const res = await fetch(route('app.csrfToken'), { headers: { Accept: 'application/json' } });
        if (!res.ok) return null;
        const data = await res.json().catch(() => null);
        const token = data?.token || null;
        if (token) {
            const meta = document.querySelector('meta[name="csrf-token"]');
            if (meta) meta.setAttribute('content', token);
        }
        return token;
    } catch {
        // genuinely offline, or the refresh itself failed — the caller's
        // own retry below just gets the same stale token back and the
        // original (already informative) response stands
        return null;
    }
}

export async function fetchWithSessionRetry(url, options) {
    let res = await fetch(url, options);

    if (res.status === 401) {
        await new Promise((resolve) => setTimeout(resolve, 800));
        res = await fetch(url, options);
    } else if (res.status === 419) {
        const token = await refreshCsrfToken();
        if (token && options?.headers && 'X-CSRF-TOKEN' in options.headers) {
            res = await fetch(url, { ...options, headers: { ...options.headers, 'X-CSRF-TOKEN': token } });
        }
    }

    return res;
}
