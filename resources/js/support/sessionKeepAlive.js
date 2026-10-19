import { refreshCsrfToken } from '@/support/fetchWithSessionRetry';

// See docs/SESSION_FIX.md. The actual fix for a raw-fetch call (POS
// checkout, offline-sync replay, etc.) hitting a stale CSRF token is
// fetchWithSessionRetry's own after-the-fact recovery — but "after the
// fact" still means ONE failed attempt first. This is the proactive half:
// keep the page's meta tag (and the session's own last-activity clock)
// fresh the entire time the app is open, so that failed-attempt-then-retry
// path is rarely even needed in practice. A POS tab is routinely left open
// for an entire shift with the screen just sitting there between
// customers — exactly the case a plain page-load-time token would go
// stale on.
let started = false;
let intervalId = null;

const TEN_MINUTES_MS = 10 * 60 * 1000;

export function startSessionKeepAlive() {
    if (started || typeof window === 'undefined') return;
    started = true;

    const tick = () => {
        if (navigator.onLine) refreshCsrfToken();
    };

    intervalId = setInterval(tick, TEN_MINUTES_MS);

    // Catches the much more common real-world case directly: a phone
    // locked/backgrounded for a couple of hours between customers, then
    // unlocked right as the next sale starts — exactly when a fresh token
    // matters most, and exactly when a background setInterval is the LEAST
    // reliable (mobile browsers routinely suspend timers for a backgrounded
    // tab, so the 10-minute tick above can't be counted on alone here).
    document.addEventListener('visibilitychange', () => {
        if (document.visibilityState === 'visible') tick();
    });
    window.addEventListener('online', tick);
}

export function stopSessionKeepAlive() {
    if (intervalId) clearInterval(intervalId);
    intervalId = null;
    started = false;
}
