import { describe, expect, it } from 'vitest';
import { buildMemoShareMetadata, memoPrintWidthDots } from '../bluetoothMemoPrint';

describe('memoPrintWidthDots', () => {
    it('80mm paper prints at 576 dots (72mm printable)', () => {
        expect(memoPrintWidthDots('80mm')).toBe(576);
    });

    it('58mm paper prints at 384 dots (48mm printable)', () => {
        expect(memoPrintWidthDots('58mm')).toBe(384);
    });

    it('missing/unknown width falls back to the 58mm default, matching paperClass', () => {
        expect(memoPrintWidthDots(undefined)).toBe(384);
        expect(memoPrintWidthDots('')).toBe(384);
    });
});

describe('buildMemoShareMetadata', () => {
    it('declares continuous paper (gap 0) with mm sizes derived from dots', () => {
        const meta = JSON.parse(buildMemoShareMetadata(576, 2400));
        expect(meta).toEqual({ widthMm: 72, heightMm: 300, gapMm: 0 });
    });

    it('rounds a fractional height to one decimal', () => {
        const meta = JSON.parse(buildMemoShareMetadata(384, 1001));
        expect(meta.heightMm).toBe(125.1);
    });
});

import { vi } from 'vitest';
import { withDeadline } from '../bluetoothMemoPrint';

describe('withDeadline', () => {
    it('passes a result through when the step finishes in time', async () => {
        await expect(withDeadline(Promise.resolve(42), 1000, 'x')).resolves.toBe(42);
    });

    it('turns a step that never finishes into an error that names the step', async () => {
        vi.useFakeTimers();
        const stuck = new Promise(() => {});
        const result = withDeadline(stuck, 15000, 'বিলের ছবি আঁকা');
        const assertion = expect(result).rejects.toMatchObject({ name: 'MemoStageTimeout', message: 'বিলের ছবি আঁকা 15 সেকেন্ডেও শেষ হয়নি' });
        await vi.advanceTimersByTimeAsync(15001);
        await assertion;
        vi.useRealTimers();
    });

    it('keeps the original error when the step fails before the deadline', async () => {
        await expect(withDeadline(Promise.reject(new Error('boom')), 1000, 'x')).rejects.toThrow('boom');
    });
});
