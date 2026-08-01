import { onBeforeUnmount, onMounted } from 'vue';
import { createScannerState, reduceScannerKey } from '@/support/scannerBuffer.js';

/**
 * Listens document-wide for a USB/Bluetooth keyboard-wedge barcode scanner
 * and calls onScan(code) whenever one fires. `enabled` may be a ref, a
 * plain boolean, or a function — checked fresh on every keystroke so a
 * shop's owner-configured on/off setting takes effect immediately without
 * needing to remount the page.
 *
 * Deliberately does NOT check document.activeElement — a cashier can be
 * mid-scan while focus happens to sit in the search box or a discount
 * field, and the scan must still register. When a scan is detected, the
 * event's own target (if it's a text input) has its value cleared instead,
 * so the barcode doesn't linger as stray typed text in an unrelated field.
 */
export function useHardwareScanner(onScan, enabled = true) {
    let state = createScannerState();

    function isEnabled() {
        if (typeof enabled === 'function') return enabled();
        if (enabled && typeof enabled === 'object' && 'value' in enabled) return enabled.value;
        return !!enabled;
    }

    function handleKeydown(e) {
        if (!isEnabled()) return;

        const { next, scanned } = reduceScannerKey(state, { key: e.key, timestamp: performance.now() });
        state = next;

        if (scanned) {
            if (e.target && 'value' in e.target) {
                e.target.value = '';
            }
            e.preventDefault();
            onScan(scanned);
        }
    }

    onMounted(() => document.addEventListener('keydown', handleKeydown, true));
    onBeforeUnmount(() => document.removeEventListener('keydown', handleKeydown, true));
}
