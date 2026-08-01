export const DEFAULT_MAX_GAP_MS = 30;
export const DEFAULT_MIN_LENGTH = 4;

export function createScannerState() {
    return { buffer: '', lastTime: 0, fastCount: 0 };
}

/**
 * A USB/Bluetooth "keyboard-wedge" barcode scanner types a barcode's
 * characters as a burst of keydown events much faster than any human can
 * sustain, then sends Enter/Tab. This is a pure reducer over that keystroke
 * stream: given the current buffering state and one incoming key event
 * (key + a millisecond timestamp), it returns the next state and, only when
 * a fast-typed run just got terminated by Enter/Tab, the scanned code.
 *
 * Kept side-effect-free (no DOM, no real timers) specifically so it can be
 * unit-tested with synthetic timestamps instead of depending on actual
 * keystroke timing — see resources/js/composables/useHardwareScanner.js for
 * the thin wrapper that feeds it real events.
 */
export function reduceScannerKey(state, { key, timestamp }, opts = {}) {
    const maxGapMs = opts.maxGapMs ?? DEFAULT_MAX_GAP_MS;
    const minLength = opts.minLength ?? DEFAULT_MIN_LENGTH;
    const gap = timestamp - state.lastTime;

    if (key === 'Enter' || key === 'Tab') {
        const code = state.buffer;
        // every gap after the first character must have been fast — a
        // human typing a 4+ character word/number quickly by chance still
        // can't sustain sub-maxGapMs on *every* keystroke the way a
        // scanner's serial burst does
        const wasFast = state.fastCount >= minLength - 1;
        const next = createScannerState();

        return wasFast && code.length >= minLength
            ? { next, scanned: code }
            : { next, scanned: null };
    }

    // ignore modifier/navigation/function keys (e.key.length > 1 for those,
    // e.g. "Shift", "ArrowLeft") — only single printable characters count
    if (key.length !== 1) {
        return { next: createScannerState(), scanned: null };
    }

    let { buffer, fastCount } = state;
    if (gap > maxGapMs) {
        buffer = '';
        fastCount = 0;
    } else if (buffer.length > 0) {
        fastCount++;
    }
    buffer += key;

    return { next: { buffer, lastTime: timestamp, fastCount }, scanned: null };
}
