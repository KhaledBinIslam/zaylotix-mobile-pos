// Pure decision logic for app.js's router 'exception' handler — split out
// so it's unit-testable the same way offlineQueue.js's functions are,
// without needing to mock Inertia's router/DOM event machinery.
export function navErrorMessage(isOnline) {
    // Offline: the actual, fixable cause almost always IS connectivity (a
    // failed Inertia visit has no HTTP response at all in that case — see
    // docs/FIX_PLAN.md) — tell the cashier plainly, and reassure them
    // selling still works (the one thing they actually came here to do).
    return isOnline ? 'offlineNav.genericError' : 'offlineNav.needsInternet';
}
