import { describe, expect, it } from 'vitest';
import { navErrorMessage } from '../navErrorMessage.js';

// Phase 12 (docs/FIX_PLAN.md) — a failed Inertia navigation while offline
// used to show a generic "something went wrong" toast instead of a clear,
// actionable one. This covers the message-selection logic app.js's
// router 'exception' handler delegates to.
describe('navErrorMessage', () => {
    it('shows the offline-specific message when offline', () => {
        expect(navErrorMessage(false)).toBe('offlineNav.needsInternet');
    });

    it('shows a generic network-issue message when online (a flaky/transient failure)', () => {
        expect(navErrorMessage(true)).toBe('offlineNav.genericError');
    });
});
