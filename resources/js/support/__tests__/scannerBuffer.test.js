import { describe, expect, it } from 'vitest';
import { createScannerState, reduceScannerKey } from '../scannerBuffer.js';

/** Feeds a whole string as fast (1ms apart) keystrokes, then Enter, and returns the final scan result. */
function feedFast(chars, { gapMs = 1, startAt = 1000, opts } = {}) {
    let state = createScannerState();
    let t = startAt;
    for (const ch of chars) {
        ({ next: state } = reduceScannerKey(state, { key: ch, timestamp: t }, opts));
        t += gapMs;
    }
    return reduceScannerKey(state, { key: 'Enter', timestamp: t }, opts);
}

describe('reduceScannerKey', () => {
    it('recognizes a fast 13-digit barcode terminated by Enter', () => {
        const { scanned } = feedFast('8901234567890');
        expect(scanned).toBe('8901234567890');
    });

    it('recognizes Tab as a terminator too', () => {
        let state = createScannerState();
        let t = 0;
        for (const ch of '12345') {
            ({ next: state } = reduceScannerKey(state, { key: ch, timestamp: t }));
            t += 1;
        }
        const { scanned } = reduceScannerKey(state, { key: 'Tab', timestamp: t });
        expect(scanned).toBe('12345');
    });

    it('does not treat slow human typing as a scan', () => {
        // 100ms between keystrokes — far slower than the 30ms default threshold
        const { scanned } = feedFast('12345', { gapMs: 100 });
        expect(scanned).toBeNull();
    });

    it('rejects a code shorter than the minimum length even if fast', () => {
        const { scanned } = feedFast('12', { gapMs: 1 });
        expect(scanned).toBeNull();
    });

    it('a single slow keystroke in the middle of an otherwise-fast run breaks the streak', () => {
        let state = createScannerState();
        let t = 0;
        const fastPart = '123';
        for (const ch of fastPart) {
            ({ next: state } = reduceScannerKey(state, { key: ch, timestamp: t }));
            t += 1;
        }
        t += 200; // a human pause
        ({ next: state } = reduceScannerKey(state, { key: '4', timestamp: t }));
        t += 1;
        ({ next: state } = reduceScannerKey(state, { key: '5', timestamp: t }));
        t += 1;
        const { scanned } = reduceScannerKey(state, { key: 'Enter', timestamp: t });
        // the slow gap resets the buffer, so only "45" was ever fast-typed — too short
        expect(scanned).toBeNull();
    });

    it('ignores modifier/navigation keys without corrupting the buffer state incorrectly', () => {
        let state = createScannerState();
        let t = 0;
        for (const ch of 'ABCD') {
            ({ next: state } = reduceScannerKey(state, { key: ch, timestamp: t }));
            t += 1;
        }
        // a stray Shift keydown arrives mid-scan (its own keyup doesn't reach this reducer)
        ({ next: state } = reduceScannerKey(state, { key: 'Shift', timestamp: t }));
        t += 1;
        const { scanned } = reduceScannerKey(state, { key: 'Enter', timestamp: t });
        // the modifier key reset the buffer entirely — nothing left to scan
        expect(scanned).toBeNull();
    });

    it('an empty Enter press (no buffer at all) never scans', () => {
        const state = createScannerState();
        const { scanned } = reduceScannerKey(state, { key: 'Enter', timestamp: 0 });
        expect(scanned).toBeNull();
    });

    it('respects custom minLength/maxGapMs options', () => {
        const opts = { minLength: 6, maxGapMs: 10 };
        const shortResult = feedFast('12345', { gapMs: 1, opts }); // 5 chars, below minLength 6
        expect(shortResult.scanned).toBeNull();

        const longResult = feedFast('123456', { gapMs: 1, opts });
        expect(longResult.scanned).toBe('123456');

        const tooSlowResult = feedFast('123456', { gapMs: 15, opts }); // above maxGapMs 10
        expect(tooSlowResult.scanned).toBeNull();
    });

    it('state resets cleanly after a successful scan for the next one', () => {
        const first = feedFast('11112222', { gapMs: 1 });
        expect(first.scanned).toBe('11112222');
        expect(first.next).toEqual(createScannerState());
    });
});
