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
