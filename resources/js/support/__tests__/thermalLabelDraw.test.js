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

/** SEAMAN Phase 7 — the detailed template. The classic suite above IS the
 *  Baby-Park-unchanged guarantee: none of it mentions `template`, so it
 *  keeps exercising the exact pre-Phase-7 drawing path. */
describe('drawThermalLabel — detailed template', () => {
    const DETAILED = { ...SETTINGS, template: 'detailed', price_mode: 'single' };
    const SEAMAN_LABEL = {
        barcode: 'ZV1234', price: 1250,
        name: 'Peak Cap Senior', brandName: 'Lotus', variantLabel: '20½',
    };

    function drawDetailed(overrides = {}) {
        const canvas = recordingCanvas(W, H);
        drawThermalLabel(canvas, {
            label: SEAMAN_LABEL,
            settings: DETAILED,
            // the same EDITABLE label-header setting classic uses — the
            // owner sets it to seamanstore.com, it is never hard-coded
            headerText: 'seamanstore.com',
            barcodeCanvas: { width: 280, height: 64 },
            ...overrides,
        });

        return canvas;
    }

    it('lays out website, bars, name, brand, size, price — top to bottom', () => {
        const calls = drawDetailed().calls;
        const at = (match) => calls.find((c) => c.op === 'fillText' && c.text.includes(match))?.y;
        const bars = calls.find((c) => c.op === 'drawImage');

        expect(at('seamanstore.com')).toBeLessThan(bars.y);
        expect(at('Peak Cap Senior')).toBeGreaterThan(bars.y + bars.h - 1);
        expect(at('Brand: Lotus')).toBeGreaterThan(at('Peak Cap Senior'));
        expect(at('20½')).toBeGreaterThan(at('Brand: Lotus'));
        expect(at('৳1250')).toBeGreaterThan(at('20½'));
    });

    it('prints Brand: Nil when there is no brand — never a blank', () => {
        const calls = drawDetailed({ label: { ...SEAMAN_LABEL, brandName: null } }).calls;
        expect(calls.some((c) => c.op === 'fillText' && c.text === 'Brand: Nil')).toBe(true);
    });

    it('drops the size line when the item has none, and the header line when the shop has none', () => {
        const calls = drawDetailed({
            label: { ...SEAMAN_LABEL, variantLabel: '' },
            headerText: '',
        }).calls;
        expect(calls.some((c) => c.op === 'fillText' && c.text.includes('20½'))).toBe(false);
        expect(calls.some((c) => c.op === 'fillText' && c.text.includes('seamanstore'))).toBe(false);
        // with no header text the bars move to the very top block
        const bars = calls.find((c) => c.op === 'drawImage');
        const firstText = calls.filter((c) => c.op === 'fillText').sort((a, b) => a.y - b.y)[0];
        expect(bars.y).toBeLessThan(firstText.y);
    });

    it('prints "Price: Nil", never ৳0, when the price is empty or zero — detailed only', () => {
        for (const price of [0, null, undefined, '0']) {
            const calls = drawDetailed({ label: { ...SEAMAN_LABEL, price, discount_price: null } }).calls;
            expect(calls.some((c) => c.op === 'fillText' && c.text === 'Price: Nil')).toBe(true);
            expect(calls.some((c) => c.op === 'fillText' && c.text.includes('৳'))).toBe(false);
        }

        // a real price still prints as money, Nil nowhere in sight
        const calls = drawDetailed().calls;
        expect(calls.some((c) => c.op === 'fillText' && c.text === '৳1250')).toBe(true);
        expect(calls.some((c) => c.op === 'fillText' && c.text === 'Price: Nil')).toBe(false);

        // and the CLASSIC sticker keeps its exact old behaviour (৳0),
        // because Baby Park's output is pinned byte-for-byte
        const classic = draw({ label: { ...LABEL, price: 0 } }).calls;
        expect(classic.some((c) => c.op === 'fillText' && c.text === '৳0')).toBe(true);
    });

    it('text gives way, never the bars: everything stays on the sticker with a long name', () => {
        const calls = drawDetailed({
            label: { ...SEAMAN_LABEL, name: 'White Uniform with Shoulder - Half Sleeve President Toray Extra Long Name' },
        }).calls;
        const bars = calls.find((c) => c.op === 'drawImage');

        expect(bars.h).toBe(64); // barcode height untouched
        for (const c of calls) {
            expect(c.y).toBeGreaterThanOrEqual(0);
            expect(c.y).toBeLessThan(H);
        }
        // the too-long name was ellipsised, not run off the edge
        const name = calls.find((c) => c.op === 'fillText' && c.text.startsWith('White Uniform'));
        expect(name.text.endsWith('…')).toBe(true);
    });

    it('keeps the classic sticker byte-identical when template is absent', () => {
        const classic = draw().calls;
        const explicit = draw({ settings: { ...SETTINGS, template: 'classic' } }).calls;
        expect(explicit).toEqual(classic);
    });
});
