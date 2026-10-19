// Pure decision logic for useOfflineSync.js's maybeAutoSync() — split out
// so the actual "does review mode skip auto-sync" rule is unit-testable
// without needing to mock Inertia's usePage()/timers/IndexedDB together.
// See docs/FIX_PLAN.md Phase 12.
export function shouldAutoSync(syncMode) {
    // Any value other than the explicit 'review' opt-in behaves exactly
    // like this app always has — 'auto', undefined (an older cached
    // client, or a shop that predates this setting), anything else.
    return syncMode !== 'review';
}
