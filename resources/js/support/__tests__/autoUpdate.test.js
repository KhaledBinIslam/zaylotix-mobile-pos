import { describe, expect, it } from 'vitest';
import { IDLE_BEFORE_RELOAD_MS, shouldAutoReload } from '../autoUpdate';

describe('shouldAutoReload — only at a safe moment', () => {
    const now = 1_000_000;

    it('never while the person is actively using the screen', () => {
        expect(shouldAutoReload({ now, lastInputAt: now - 5_000, online: true })).toBe(false);
        expect(shouldAutoReload({ now, lastInputAt: now - (IDLE_BEFORE_RELOAD_MS - 1), online: true })).toBe(false);
    });

    it('once the screen has been untouched for the idle window', () => {
        expect(shouldAutoReload({ now, lastInputAt: now - IDLE_BEFORE_RELOAD_MS, online: true })).toBe(true);
        expect(shouldAutoReload({ now, lastInputAt: now - 10 * 60 * 1000, online: true })).toBe(true);
    });

    it('never while offline — a reload would land on the cached shell, not the new build', () => {
        expect(shouldAutoReload({ now, lastInputAt: now - 60 * 60 * 1000, online: false })).toBe(false);
    });

    it('honours a custom idle window', () => {
        expect(shouldAutoReload({ now, lastInputAt: now - 20_000, online: true, idleMs: 15_000 })).toBe(true);
        expect(shouldAutoReload({ now, lastInputAt: now - 10_000, online: true, idleMs: 15_000 })).toBe(false);
    });
});
