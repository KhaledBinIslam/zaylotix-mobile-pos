// Real module-level state — unlike a `let` declared inside a <script setup>
// block (which the SFC compiler puts INSIDE setup(), making it per-component-
// instance, not actually shared — the bug this file was extracted to fix),
// a plain .js module's top-level bindings are singleton-cached by the
// bundler/runtime and genuinely shared by every importer. See Sheet.vue's
// own comment for why this needs to be shared across every Sheet instance
// in the app, not per-instance.
let openDepth = 0;
let weOwnHistoryEntry = false;

/*
 * The pop WE ask for must never reach Inertia.
 *
 * Closing the last open sheet from code (✕, a finished submit, the billing
 * confirm sheet closing as checkout starts) pops our throwaway history entry
 * with history.back(). Inertia's own global popstate handler cannot tell
 * that from a real navigation: it "restores" the page with preserveState:
 * false, which REMOUNTS it — wiping every local ref and re-running the
 * cart-recovery restore. At checkout that is the worst possible moment: the
 * bill is already on its way to the server, the remounted page restores the
 * cart as if nothing happened ("কার্ট ফিরিয়ে আনা হয়েছে — আবার বিল করুন চাপুন"),
 * the receipt never shows, and the cashier bills the same goods twice.
 * Found by driving the real POS in a browser (QA 2026-11-26), and matching
 * production: identical bills by one cashier 2-7 seconds apart.
 *
 * So: remember that WE initiated the back, and swallow exactly that one
 * popstate in the capture phase, before Inertia's (bubble-phase) listener.
 * A real back-button press carries no such flag and is handled as before
 * (Inertia remount + cart snapshot restore, see Pos/Index.vue).
 */
let ownBackPending = false;
let ownBackTimer = null;

function swallowOwnPop(event) {
    if (!ownBackPending) return;
    ownBackPending = false;
    clearTimeout(ownBackTimer);
    event.stopImmediatePropagation();
}

/** Registers the guard on `target` (the window). Exported so a test can use a fake window. */
export function installOwnPopGuard(target) {
    target.addEventListener('popstate', swallowOwnPop, true);
}

if (typeof window !== 'undefined') installOwnPopGuard(window);

export function sheetOpened() {
    openDepth++;
    if (openDepth === 1 && !weOwnHistoryEntry) {
        history.pushState({ ...history.state, zaylotixSheet: true }, '');
        weOwnHistoryEntry = true;
    }
}

/** @param {boolean} viaPopstate — true if the browser already consumed the history entry itself (a real back press), false for a programmatic/UI close (✕, scrim, successful submit, ...). */
export function sheetClosed(viaPopstate) {
    openDepth = Math.max(0, openDepth - 1);
    if (viaPopstate) {
        weOwnHistoryEntry = false;
        return;
    }
    // Deferred, not immediate: if another sheet opens in the same
    // synchronous tick (switching sheets, or a checkout success closing
    // the cart sheet and opening the receipt sheet right after), openDepth
    // will already be back above zero by the time this runs, and a real
    // history.back() must never fire for that case — see Sheet.vue.
    queueMicrotask(() => {
        if (openDepth === 0 && weOwnHistoryEntry && history.state?.zaylotixSheet) {
            weOwnHistoryEntry = false;
            ownBackPending = true;
            // if the browser never delivers the popstate, do not let the flag swallow a genuine back press later
            clearTimeout(ownBackTimer);
            ownBackTimer = setTimeout(() => { ownBackPending = false; }, 1500);
            history.back();
        }
    });
}
