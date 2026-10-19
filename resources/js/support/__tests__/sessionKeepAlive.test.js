import { describe, expect, it, vi, beforeEach, afterEach } from 'vitest';
import { startSessionKeepAlive, stopSessionKeepAlive } from '../sessionKeepAlive.js';
import { refreshCsrfToken } from '../fetchWithSessionRetry.js';

// refreshCsrfToken is the one real side effect this module has — mocked
// so these tests check WHEN it's called, not its own internals (already
// covered by fetchWithSessionRetry.test.js).
vi.mock('../fetchWithSessionRetry.js', () => ({ refreshCsrfToken: vi.fn() }));

describe('sessionKeepAlive', () => {
    let listeners;

    beforeEach(() => {
        vi.useFakeTimers();
        listeners = { window: {}, document: {} };
        vi.stubGlobal('navigator', { onLine: true });
        vi.stubGlobal('window', { addEventListener: (event, fn) => { listeners.window[event] = fn; } });
        vi.stubGlobal('document', {
            visibilityState: 'visible',
            addEventListener: (event, fn) => { listeners.document[event] = fn; },
        });
    });

    afterEach(() => {
        stopSessionKeepAlive(); // resets the module's own `started` singleton flag between tests
        vi.useRealTimers();
        vi.unstubAllGlobals();
        vi.clearAllMocks();
    });

    it('refreshes the token every 10 minutes while online', async () => {
        startSessionKeepAlive();
        expect(refreshCsrfToken).not.toHaveBeenCalled();

        await vi.advanceTimersByTimeAsync(10 * 60 * 1000);
        expect(refreshCsrfToken).toHaveBeenCalledTimes(1);

        await vi.advanceTimersByTimeAsync(10 * 60 * 1000);
        expect(refreshCsrfToken).toHaveBeenCalledTimes(2);
    });

    it('does not ping while offline', async () => {
        vi.stubGlobal('navigator', { onLine: false });
        startSessionKeepAlive();

        await vi.advanceTimersByTimeAsync(10 * 60 * 1000);

        expect(refreshCsrfToken).not.toHaveBeenCalled();
    });

    it('refreshes immediately when the tab becomes visible again', () => {
        startSessionKeepAlive();
        listeners.document.visibilitychange();

        expect(refreshCsrfToken).toHaveBeenCalledTimes(1);
    });

    it('does not refresh on a visibilitychange INTO hidden', () => {
        vi.stubGlobal('document', {
            visibilityState: 'hidden',
            addEventListener: (event, fn) => { listeners.document[event] = fn; },
        });
        startSessionKeepAlive();
        listeners.document.visibilitychange();

        expect(refreshCsrfToken).not.toHaveBeenCalled();
    });

    it('refreshes on the window coming back online', () => {
        startSessionKeepAlive();
        listeners.window.online();

        expect(refreshCsrfToken).toHaveBeenCalledTimes(1);
    });

    it('only starts once even if called twice', async () => {
        startSessionKeepAlive();
        startSessionKeepAlive();
        await vi.advanceTimersByTimeAsync(10 * 60 * 1000);

        expect(refreshCsrfToken).toHaveBeenCalledTimes(1); // not 2 — a second interval would double this
    });
});
