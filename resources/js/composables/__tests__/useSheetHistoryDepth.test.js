import { describe, expect, it, vi, beforeEach, afterEach } from 'vitest';

// Regression test for the "বিল হচ্ছে না" bug: a checkout success handler
// closes the cart sheet and opens the receipt sheet in the same
// synchronous tick. If that ever calls a real history.back() for the
// close, it triggers a popstate Inertia's own global handler reacts to
// with a full page remount — wiping the cart/receipt state that was just
// set. This module is what decides whether back() actually fires; these
// tests pin that decision down directly, without needing a real browser.
async function flushMicrotasks() {
    await Promise.resolve();
    await Promise.resolve();
}

describe('useSheetHistoryDepth', () => {
    let pushState;
    let back;
    let state;

    beforeEach(async () => {
        vi.resetModules();
        state = {};
        pushState = vi.fn((data) => { state = data; });
        back = vi.fn();
        global.history = {
            get state() { return state; },
            pushState,
            back,
        };
    });
    afterEach(() => {
        vi.restoreAllMocks();
    });

    it('pushes exactly one history entry when a sheet opens', async () => {
        const { sheetOpened } = await import('../useSheetHistoryDepth.js');
        sheetOpened();

        expect(pushState).toHaveBeenCalledTimes(1);
        expect(state.zaylotixSheet).toBe(true);
    });

    it('closing and stopping there (no other sheet opens) calls history.back() once the microtask settles', async () => {
        const { sheetOpened, sheetClosed } = await import('../useSheetHistoryDepth.js');
        sheetOpened();
        sheetClosed(false);

        expect(back).not.toHaveBeenCalled(); // not yet — deferred
        await flushMicrotasks();
        expect(back).toHaveBeenCalledTimes(1);
    });

    it('closing one sheet and opening another in the SAME tick never calls history.back() — the actual regression', async () => {
        const { sheetOpened, sheetClosed } = await import('../useSheetHistoryDepth.js');
        // cart sheet opens
        sheetOpened();
        pushState.mockClear();

        // checkout succeeds: cart sheet closes, receipt sheet opens — both
        // synchronously, in the same tick, exactly like resetCartAfterCheckout()
        // followed immediately by receiptOpen.value = true
        sheetClosed(false);
        sheetOpened();

        await flushMicrotasks();

        expect(back).not.toHaveBeenCalled();
        // and no SECOND pushState either — the original entry is reused,
        // not replaced
        expect(pushState).not.toHaveBeenCalled();
    });

    it('a real hardware back press (viaPopstate) never double-pops — no further back() call once the browser already consumed the entry', async () => {
        const { sheetOpened, sheetClosed } = await import('../useSheetHistoryDepth.js');
        sheetOpened();
        sheetClosed(true); // the browser's own back navigation already happened
        await flushMicrotasks();

        expect(back).not.toHaveBeenCalled();
    });

    it('nested opens (a second sheet opens while the first is still open) collapse into one history entry and one eventual back()', async () => {
        const { sheetOpened, sheetClosed } = await import('../useSheetHistoryDepth.js');
        sheetOpened(); // outer sheet (e.g. cart)
        sheetOpened(); // inner sheet (e.g. weight-entry), opened on top
        expect(pushState).toHaveBeenCalledTimes(1); // only one real entry for both

        sheetClosed(false); // inner closes
        await flushMicrotasks();
        expect(back).not.toHaveBeenCalled(); // outer is still open — must not pop yet

        sheetClosed(false); // outer closes
        await flushMicrotasks();
        expect(back).toHaveBeenCalledTimes(1);
    });
});
