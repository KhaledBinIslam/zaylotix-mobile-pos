import { describe, it, expect } from 'vitest';
import { drawThermalLabel } from '../thermalLabelCanvas';

/**
 * A canvas that records what was drawn instead of drawing it.
 *
 * There is no browser and no canvas library here, and the printer is at
 * the shop — so this is how the sticker's geometry gets checked at all:
 * the order of the blocks, that they stay inside the label, and that the
 * bars land on whole dots.
 */
function recordingCanvas(width, height) {
    const calls = [];
    const ctx = {
        font: '',
        fillStyle: '',
        textAlign: '',
        textBaseline: '',
        imageSmoothingEnabled: true,
        measureText: (text) => {
            // 0.6em per character is close enough to a real sans-serif to
            // exercise the shrink-to-fit loop
            const size = parseInt(/(\d+)px/.exec(ctx.font)?.[1] ?? '10', 10);

            return { width: text.length * size * 0.6 };
        },
        fillRect: (x, y, w, h) => calls.push({ op: 'fillRect', x, y, w, h, fill: ctx.fillStyle }),
        fillText: (text, x, y) => calls.push({ op: 'fillText', text, x, y, font: ctx.font }),
        drawImage: (img, x, y) => calls.push({ op: 'drawImage', x, y, w: img.width, h: img.height, smoothing: ctx.imageSmoothingEnabled }),
    };

    return { width, height, getContext: () => ctx, calls };
}

const LABEL = { barcode: 'SMS-L-10001-NSXXZ-WHT-36', price: 1190 };
const SETTINGS = { show_shop_name: true, price_mode: 'final' };
// a 40x30mm label at 8 dots/mm
const W = 320;
const H = 240;

function draw(overrides = {}) {
    const canvas = recordingCanvas(W, H);
    drawThermalLabel(canvas, {
        label: LABEL,
        settings: SETTINGS,
        headerText: 'Baby Park & Liberty',
        barcodeCanvas: { width: 280, height: 64 },
        ...overrides,
    });

    return canvas;
}

describe('drawThermalLabel', () => {
    it('starts from a white label', () => {
        const first = draw().calls[0];
        expect(first).toMatchObject({ op: 'fillRect', x: 0, y: 0, w: W, h: H, fill: '#ffffff' });
    });

    it('lays the three blocks down the sticker in order', () => {
        const calls = draw().calls;
        const header = calls.find((c) => c.op === 'fillText' && c.text.includes('Baby Park'));
        const bars = calls.find((c) => c.op === 'drawImage');
        const price = calls.find((c) => c.op === 'fillText' && c.text.includes('৳'));

        expect(header).toBeTruthy();
        expect(bars.y).toBeGreaterThan(header.y);
        expect(price.y).toBeGreaterThan(bars.y + bars.h - 1);
    });

    it('keeps everything on the sticker', () => {
        for (const c of draw().calls) {
            expect(c.y).toBeGreaterThanOrEqual(0);
            expect(c.y).toBeLessThan(H);
        }
    });

    it('centres the bars on a whole dot, with smoothing off', () => {
        const bars = draw().calls.find((c) => c.op === 'drawImage');
        // a bar landing on half a dot is what stops a printed barcode scanning
        expect(Number.isInteger(bars.x)).toBe(true);
        expect(bars.x).toBe(Math.round((W - 280) / 2));
        expect(bars.smoothing).toBe(false);
    });

    it('shrinks a long shop name instead of letting it run off the edge', () => {
        const long = draw({ headerText: 'Baby Park & Liberty Footwear Collection Dhaka' });
        const short = draw({ headerText: 'AB' });
        const sizeOf = (canvas) => parseInt(/(\d+)px/.exec(
            canvas.calls.find((c) => c.op === 'fillText').font,
        )[1], 10);

        expect(sizeOf(long)).toBeLessThan(sizeOf(short));
    });

    it('drops the header when the shop turned it off, and starts higher', () => {
        const withHeader = draw().calls.find((c) => c.op === 'drawImage');
        const without = draw({ settings: { ...SETTINGS, show_shop_name: false } });

        expect(without.calls.some((c) => c.op === 'fillText' && c.text.includes('Baby Park'))).toBe(false);
        expect(without.calls.find((c) => c.op === 'drawImage').y).toBeLessThan(withHeader.y);
    });

    it('prints no price when the shop turned it off', () => {
        const calls = draw({ settings: { ...SETTINGS, price_mode: 'none' } }).calls;
        expect(calls.some((c) => c.op === 'fillText' && c.text.includes('৳'))).toBe(false);
    });

    it('strikes through the regular price when both are shown', () => {
        const calls = draw({
            label: { ...LABEL, discount_price: 990 },
            settings: { ...SETTINGS, price_mode: 'both' },
        }).calls;

        expect(calls.some((c) => c.op === 'fillText' && c.text === 'নিয়মিত ৳1190')).toBe(true);
        expect(calls.some((c) => c.op === 'fillText' && c.text === 'ছাড় ৳990')).toBe(true);
        // the strike-through is a thin filled bar drawn after the label was cleared
        expect(calls.filter((c) => c.op === 'fillRect').length).toBeGreaterThan(1);
    });

    it('still draws a sticker with no barcode at all', () => {
        const calls = draw({ barcodeCanvas: null }).calls;
        expect(calls.some((c) => c.op === 'drawImage')).toBe(false);
        expect(calls.some((c) => c.op === 'fillText' && c.text.includes('৳'))).toBe(true);
    });
});
