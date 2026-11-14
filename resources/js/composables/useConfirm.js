import { ref } from 'vue';

// Module-level (shared by every importer, not per-component) — one
// <ConfirmDialog/> mounted once in AppLayout.vue renders whatever the
// most recent confirmAsync() call asked for, regardless of which page or
// component called it. See ConfirmDialog.vue's own docblock for why this
// exists: window.confirm()/confirm() is a native blocking dialog that's
// known to get silently auto-dismissed (returns false, exactly like the
// user tapped Cancel, nothing to show for it) inside some Android
// WebView/PWA "add to home screen" setups — precisely how this app runs
// on a shop's own device. Real bug traced live this way on a clothing
// demo shop: 0 sales ever went through, "বিল আসছে না" with nothing visible.
export const confirmState = ref({ open: false, message: '', resolve: null });

/** Drop-in async replacement for `confirm(message)` — `if (!(await confirmAsync(msg))) return;` */
export function confirmAsync(message) {
    return new Promise((resolve) => {
        confirmState.value = { open: true, message, resolve };
    });
}
