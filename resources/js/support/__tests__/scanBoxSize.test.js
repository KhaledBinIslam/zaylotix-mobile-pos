import { describe, it, expect } from 'vitest';
import { scanBoxSize } from '../scanBoxSize';

describe('scanBoxSize', () => {
    it('is wide and short, matching a 1D barcode\'s shape, not a square', () => {
        const box = scanBoxSize(800, 600);
        expect(box.width).toBeGreaterThan(box.height);
    });

    it('never exceeds the viewfinder (html5-qrcode throws if it does)', () => {
        const box = scanBoxSize(300, 200);
        expect(box.width).toBeLessThanOrEqual(300);
        expect(box.height).toBeLessThanOrEqual(200);
    });

    it('is capped on a large viewfinder instead of growing unbounded', () => {
        const box = scanBoxSize(2000, 1500);
        expect(box.width).toBeLessThanOrEqual(380);
        expect(box.height).toBeLessThanOrEqual(160);
    });

    it('is noticeably wider than the old fixed 260x200 box on a typical phone viewfinder', () => {
        const box = scanBoxSize(360, 640); // a common portrait phone viewport width
        expect(box.width).toBeGreaterThan(260);
    });
});
