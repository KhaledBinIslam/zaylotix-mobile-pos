import { describe, expect, it } from 'vitest';
import { shouldAutoSync } from '../syncModeDecision.js';

describe('shouldAutoSync', () => {
    it('auto-syncs in auto mode (unchanged default behavior)', () => {
        expect(shouldAutoSync('auto')).toBe(true);
    });

    it('does not auto-sync in review mode', () => {
        expect(shouldAutoSync('review')).toBe(false);
    });

    it('defaults to auto-syncing for an undefined/unset mode (older client or a shop that predates this setting)', () => {
        expect(shouldAutoSync(undefined)).toBe(true);
        expect(shouldAutoSync(null)).toBe(true);
    });
});
