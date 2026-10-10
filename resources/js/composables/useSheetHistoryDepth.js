// Real module-level state — unlike a `let` declared inside a <script setup>
// block (which the SFC compiler puts INSIDE setup(), making it per-component-
// instance, not actually shared — the bug this file was extracted to fix),
// a plain .js module's top-level bindings are singleton-cached by the
// bundler/runtime and genuinely shared by every importer. See Sheet.vue's
// own comment for why this needs to be shared across every Sheet instance
// in the app, not per-instance.
let openDepth = 0;
let weOwnHistoryEntry = false;

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
            history.back();
        }
    });
}
