import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

/*
 * The billing confirm sheet closing as checkout starts used to pop our throwaway history entry with
 * history.back(); Inertia treated that as a real navigation, remounted the POS and restored the already-billed
 * cart. The pop WE ask for must not reach Inertia — and a genuine back press must still reach it.
 */
let fakeWindow;
let fakeHistory;
let inertiaSaw;
let mod;

beforeEach(async () => {
    vi.useFakeTimers();
    vi.resetModules();
    fakeWindow = new EventTarget();
    inertiaSaw = 0;
    fakeHistory = {
        state: { page: 'pos' },
        pushState(s) { this.state = s; },
        back() { setTimeout(() => fakeWindow.dispatchEvent(new Event('popstate')), 0); },
    };
    vi.stubGlobal('history', fakeHistory);
    // as in the real app: app.js imports the guard BEFORE Inertia's router registers its own popstate listener
    mod = await import('../useSheetHistoryDepth');
    mod.installOwnPopGuard(fakeWindow);
    fakeWindow.addEventListener('popstate', () => { inertiaSaw++; });
});

afterEach(() => {
    vi.useRealTimers();
    vi.unstubAllGlobals();
});

describe('useSheetHistoryDepth', () => {
    it('the pop we ask for (closing the last sheet from code) never reaches Inertia', async () => {
        mod.sheetOpened();
        expect(fakeHistory.state.zaylotixSheet).toBe(true);

        mod.sheetClosed(false);            // e.g. the confirm sheet closing as checkout starts
        await vi.advanceTimersByTimeAsync(10);

        expect(inertiaSaw).toBe(0);
    });

    it('a real back press still reaches Inertia (cart-snapshot recovery depends on that)', async () => {
        mod.sheetOpened();

        fakeWindow.dispatchEvent(new Event('popstate')); // the user pressed back; no programmatic close

        expect(inertiaSaw).toBe(1);
    });

    it('swallows only ONE pop — a later genuine back press is not eaten', async () => {
        mod.sheetOpened();
        mod.sheetClosed(false);
        await vi.advanceTimersByTimeAsync(10);

        fakeWindow.dispatchEvent(new Event('popstate'));

        expect(inertiaSaw).toBe(1);
    });

    it('if the browser never delivers our pop, the flag expires instead of eating a later back press', async () => {
        fakeHistory.back = () => {};       // popstate never arrives
        mod.sheetOpened();
        mod.sheetClosed(false);
        await vi.advanceTimersByTimeAsync(2000);

        fakeWindow.dispatchEvent(new Event('popstate'));

        expect(inertiaSaw).toBe(1);
    });

    it('closing one sheet while another is still open asks for no back at all', async () => {
        const back = vi.spyOn(fakeHistory, 'back');
        mod.sheetOpened();
        mod.sheetOpened();                 // cart + confirm
        mod.sheetClosed(false);
        await vi.advanceTimersByTimeAsync(10);

        expect(back).not.toHaveBeenCalled();
    });
});
