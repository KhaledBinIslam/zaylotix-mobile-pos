// Liberty Bali's new Bluetooth scanner (XL-9309B) sends its burst with
// wider gaps than a wired USB scanner -- 30ms flagged every real scan from
// it as "too slow" and let the raw digits fall through as literal typed
// text in whatever field had focus (Khaled: "scan e pore thake kichu
// hoina"). 60ms still rejects the test suite's 100ms human-typing case
// (nobody sustains sub-60ms on every keystroke of a barcode by accident)
// while giving BLE HID's extra latency room to still register as a scan.
export const DEFAULT_MAX_GAP_MS = 60;
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

    // Liberty Bali's wired USB scanner (Khaled live, "Product not found:
    // C807"): many keyboard-wedge scanners press/release Shift around EVERY
    // uppercase character instead of holding it, so a real "ZC807" arrives
    // as Shift, Z, Shift, C, 8, 0, 7 — and treating that mid-burst Shift as
    // "human doing something else" wiped the buffer, chopping the code to
    // its tail after the last Shift ("C807", which matches nothing). Shift
    // is part of how a scanner TYPES, not a sign the scan ended — pass it
    // through, keeping the burst alive timing-wise. The speed gate below
    // still rejects human typing: nobody sustains sub-maxGapMs on every
    // keystroke of a 4+ character code by accident, shifted or not.
    if (key === 'Shift') {
        return { next: { ...state, lastTime: timestamp }, scanned: null };
    }

    // ignore other modifier/navigation/function keys (e.key.length > 1 for
    // those, e.g. "ArrowLeft", "Backspace") — only single printable
    // characters count, and any of these mid-run means a human is editing
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
