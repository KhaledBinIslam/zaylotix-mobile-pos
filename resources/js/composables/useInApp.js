const STORAGE_KEY = 'zaylotix_in_app';
const ANDROID_REFERRER = 'android-app://com.zaylotix.pos';

/**
 * Detects whether this page is running inside Zaylotix's own Android app
 * (a TWA — Trusted Web Activity — shell around this exact site), so
 * Zaylotix's own subscription-renewal payment UI and marketing pricing
 * section can be hidden per Google Play policy (no in-app purchase flow
 * that bypasses Play Billing — payment here is taken manually, outside
 * the app). Three independent signals, any ONE is enough:
 *   1. `?source=app` on the URL — the TWA's own start_url carries this.
 *   2. document.referrer === 'android-app://com.zaylotix.pos' — how
 *      Chrome marks a page opened inside a TWA, regardless of which URL
 *      within the app actually launched it (deep link, notification tap).
 *   3. display-mode: standalone — true for any installed/TWA-launched
 *      instance, including ones that opened directly past `start_url`.
 * Written to localStorage (not sessionStorage) so it survives the app
 * being fully closed and reopened later via a home-screen icon or deep
 * link that might not itself carry ?source=app — once ANY signal has
 * ever fired for this installed instance, it stays remembered.
 */
function detect() {
    const bySource = new URLSearchParams(window.location.search).get('source') === 'app';
    const byReferrer = document.referrer.startsWith(ANDROID_REFERRER);
    const byDisplayMode = window.matchMedia?.('(display-mode: standalone)').matches ?? false;

    if (bySource || byReferrer || byDisplayMode) {
        try {
            localStorage.setItem(STORAGE_KEY, '1');
        } catch {
            // private/incognito mode etc. — detection still works for this
            // page load via the `cached` in-memory value below, just won't
            // persist across a full app restart
        }

        return true;
    }

    try {
        return localStorage.getItem(STORAGE_KEY) === '1';
    } catch {
        return false;
    }
}

let cached = null;

/** Computed once per page load, then cached — this never changes during a session, no reactivity needed. */
export function isInApp() {
    if (cached === null) cached = detect();

    return cached;
}
