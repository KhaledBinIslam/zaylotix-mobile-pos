import { onBeforeUnmount, onMounted } from 'vue';

/**
 * Global function-key shortcuts for fast POS/Stock usage on a physical
 * keyboard (desktop/web view) — F-keys are never part of ordinary typing
 * (product search, customer name, kitchen note, ...), so unlike a plain
 * letter-key shortcut this never needs to check document.activeElement or
 * risk firing while a cashier is mid-sentence in a text field.
 *
 * `map` is { 'F2': () => ..., 'Escape': () => ... }.
 */
export function useKeyboardShortcuts(map) {
    function handleKeydown(e) {
        const handler = map[e.key];
        if (!handler) return;
        e.preventDefault();
        handler(e);
    }

    onMounted(() => document.addEventListener('keydown', handleKeydown));
    onBeforeUnmount(() => document.removeEventListener('keydown', handleKeydown));
}
