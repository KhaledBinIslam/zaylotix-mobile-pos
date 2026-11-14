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

    /**
     * Liberty Bali live bug ("Product not found: C807"): a wired USB
     * scanner that presses Shift separately before EVERY uppercase
     * character used to get its buffer wiped on each Shift keydown — a
     * real "ZC807" came through as just "C807" (everything before the
     * last Shift lost). Shift is part of how a scanner types uppercase,
     * never a sign the scan ended.
     */
    it('keeps the buffer across per-character Shift presses (uppercase-typing scanners)', () => {
        let state = createScannerState();
        let t = 0;
        // Shift, Z, Shift, C, 8, 0, 7 — exactly how the failing scanner types "ZC807"
        for (const key of ['Shift', 'Z', 'Shift', 'C', '8', '0', '7']) {
            ({ next: state } = reduceScannerKey(state, { key, timestamp: t }));
            t += 1;
        }
        const { scanned } = reduceScannerKey(state, { key: 'Enter', timestamp: t });
        expect(scanned).toBe('ZC807');
    });

    it('a non-Shift control key mid-run still resets the buffer (human editing)', () => {
        let state = createScannerState();
        let t = 0;
        for (const ch of 'ABCD') {
            ({ next: state } = reduceScannerKey(state, { key: ch, timestamp: t }));
            t += 1;
        }
        ({ next: state } = reduceScannerKey(state, { key: 'Backspace', timestamp: t }));
        t += 1;
        const { scanned } = reduceScannerKey(state, { key: 'Enter', timestamp: t });
        expect(scanned).toBeNull();
    });

    it('slow human typing stays rejected even with interleaved Shift presses', () => {
        let state = createScannerState();
        let t = 0;
        // a human typing "ZC807" by hand: shift+letter pairs ~150ms apart
        for (const key of ['Shift', 'Z', 'Shift', 'C', '8', '0', '7']) {
            ({ next: state } = reduceScannerKey(state, { key, timestamp: t }));
            t += 150;
        }
        const { scanned } = reduceScannerKey(state, { key: 'Enter', timestamp: t });
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
