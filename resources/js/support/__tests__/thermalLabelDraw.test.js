import { describe, it, expect } from 'vitest';
import { drawThermalLabel, brandSizeLine } from '../thermalLabelCanvas';
import { detailedBarcodeDots } from '../barcodeFit';

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
describe('drawThermalLabel — detailed template (proportional, round 3)', () => {
    const DETAILED = { ...SETTINGS, template: 'detailed', price_mode: 'single' };
    const SEAMAN_LABEL = {
        barcode: 'ZV1234', price: 1250,
        name: 'Peak Cap Senior', brandName: 'Lotus', variantLabel: '20½',
    };

    function drawDetailed(overrides = {}, w = W, h = H) {
        const canvas = recordingCanvas(w, h);
        drawThermalLabel(canvas, {
            label: SEAMAN_LABEL,
            settings: DETAILED,
            // the same EDITABLE label-header setting classic uses — the
            // owner sets it to seamanstore.com, never hard-coded
            headerText: 'seamanstore.com',
            barcodeCanvas: { width: Math.round(w * 0.75), height: Math.round(h * 0.38) },
            ...overrides,
        });

        return canvas;
    }

    const textAt = (calls, match) => calls.find((c) => c.op === 'fillText' && c.text.includes(match));

    it('lays out header / bars / ZV code / name / brand|size / price, top to bottom', () => {
        const calls = drawDetailed().calls;
        const bars = calls.find((c) => c.op === 'drawImage');

        expect(textAt(calls, 'seamanstore.com').y).toBeLessThan(bars.y);
        expect(textAt(calls, 'ZV1234').y).toBeGreaterThan(bars.y + bars.h - 1);
        expect(textAt(calls, 'Peak Cap Senior').y).toBeGreaterThan(textAt(calls, 'ZV1234').y);
        expect(textAt(calls, 'Lotus  |  Size: 20½').y).toBeGreaterThan(textAt(calls, 'Peak Cap Senior').y);
        expect(textAt(calls, '৳1250').y).toBeGreaterThan(textAt(calls, 'Lotus  |  Size: 20½').y);
    });

    it('brand and size share ONE line; Brand: Nil when none; size part dropped when none', () => {
        expect(brandSizeLine({ brandName: 'Lotus', variantLabel: '42' })).toBe('Lotus  |  Size: 42');
        expect(brandSizeLine({ brandName: null, variantLabel: '42' })).toBe('Brand: Nil  |  Size: 42');
        expect(brandSizeLine({ brandName: 'Lotus', variantLabel: '' })).toBe('Lotus');
        expect(brandSizeLine({ brandName: null, variantLabel: null })).toBe('Brand: Nil');

        const calls = drawDetailed({ label: { ...SEAMAN_LABEL, brandName: null, variantLabel: '' } }).calls;
        expect(calls.some((c) => c.op === 'fillText' && c.text === 'Brand: Nil')).toBe(true);
        expect(calls.some((c) => c.op === 'fillText' && c.text.includes('Size:'))).toBe(false);
    });

    it('prints "Price: Nil", never ৳0, when the price is empty/zero — classic keeps its old ৳0', () => {
        for (const price of [0, null, undefined, '0']) {
            const calls = drawDetailed({ label: { ...SEAMAN_LABEL, price, discount_price: null } }).calls;
            expect(calls.some((c) => c.op === 'fillText' && c.text === 'Price: Nil')).toBe(true);
            expect(calls.some((c) => c.op === 'fillText' && c.text.includes('৳'))).toBe(false);
        }
        const classic = draw({ label: { ...LABEL, price: 0 } }).calls;
        expect(classic.some((c) => c.op === 'fillText' && c.text === '৳0')).toBe(true);
    });

    it('spreads the blocks over the WHOLE label: the price sits in the bottom fifth', () => {
        for (const [w, h] of [[400, 240], [304, 200]]) { // 50×30 and 38×25 at 8 dots/mm
            const calls = drawDetailed({}, w, h).calls;
            const price = textAt(calls, '৳1250');
            expect(price.y).toBeGreaterThan(h * 0.72);
            for (const c of calls) {
                expect(c.y).toBeGreaterThanOrEqual(0);
                expect(c.y).toBeLessThan(h);
            }
        }
    });

    it('a long name wraps to two lines, then ellipsises — the bars never shrink', () => {
        const calls = drawDetailed({
            label: { ...SEAMAN_LABEL, name: 'White Uniform with Shoulder Half Sleeve President Toray Extra Long' },
        }).calls;
        const nameLines = calls.filter((c) => c.op === 'fillText' && /White Uniform|Toray|…/.test(c.text) && !c.text.includes('seamanstore'));
        expect(nameLines.length).toBe(2);
        const bars = calls.find((c) => c.op === 'drawImage');
        expect(bars.h).toBe(Math.round(H * 0.38)); // untouched
        for (const c of calls) expect(c.y).toBeLessThan(H);
    });

    it('with no header text the bars start the sticker', () => {
        const calls = drawDetailed({ headerText: '' }).calls;
        const bars = calls.find((c) => c.op === 'drawImage');
        const firstText = calls.filter((c) => c.op === 'fillText').sort((a, b) => a.y - b.y)[0];
        expect(bars.y).toBeLessThan(firstText.y);
    });

    it('keeps the classic sticker byte-identical when template is absent', () => {
        const classic = draw().calls;
        const explicit = draw({ settings: { ...SETTINGS, template: 'classic' } }).calls;
        expect(explicit).toEqual(classic);
    });
});

describe('detailedBarcodeDots — the bars scale with the label', () => {
    it('picks the widest whole-dot module within ~90% of the label, taller labels = taller bars', () => {
        const big = detailedBarcodeDots('ZV1234', 400, 240);   // 50×30mm
        const small = detailedBarcodeDots('ZV1234', 304, 200); // 38×25mm

        expect(big.moduleDots).toBeGreaterThanOrEqual(small.moduleDots);
        expect(big.modules * big.moduleDots).toBeLessThanOrEqual(400 * 0.9);
        expect(big.modules * big.moduleDots).toBeGreaterThan(400 * 0.55); // fills, not a stamp in the corner
        expect(small.modules * small.moduleDots).toBeLessThanOrEqual(304 * 0.9);
        expect(big.heightDots).toBe(Math.round(240 * 0.38));
        expect(small.heightDots).toBe(Math.round(200 * 0.38));
        expect(big.fits).toBe(true);
    });
});

describe('stock never touches label CONTENT', () => {
    it('a 0-stock variant prints every line of the detailed sticker', () => {
        const canvas = recordingCanvas(W, H);
        drawThermalLabel(canvas, {
            label: { barcode: 'ZV3571', price: 1890, name: 'Lotus Safety Shoe', brandName: 'Lotus', variantLabel: '42', stock: 0 },
            settings: { show_shop_name: true, price_mode: 'single', template: 'detailed' },
            headerText: 'seamanstore.com',
            barcodeCanvas: { width: 280, height: 64 },
        });
        const texts = canvas.calls.filter((c) => c.op === 'fillText').map((c) => c.text);

        expect(texts).toContain('seamanstore.com');
        expect(texts).toContain('ZV3571');
        expect(texts).toContain('Lotus Safety Shoe');
        expect(texts).toContain('Lotus  |  Size: 42');
        expect(texts).toContain('৳1890');
    });
});

describe('classic sticker — the readable code never widens the bars (Baby Park 327>304 fix)', () => {
    it('draws the full code below the bars, shrunk to fit, never cut off', () => {
        const calls = draw({ label: { barcode: 'CHINA-S-80001-IXXZ-BLACK-RED-36-3', price: 500 } }).calls;
        const bars = calls.find((c) => c.op === 'drawImage');
        const code = calls.find((c) => c.op === 'fillText' && c.text === 'CHINA-S-80001-IXXZ-BLACK-RED-36-3');

        expect(code).toBeTruthy(); // the WHOLE code, no ellipsis
        expect(code.y).toBeGreaterThan(bars.y + bars.h - 1);
        expect(code.font).toContain('monospace');
        // shrunk to the label, not spilling past it
        const size = parseInt(/(\d+)px/.exec(code.font)[1], 10);
        expect(code.text.length * size * 0.6).toBeLessThanOrEqual(W - 2 * Math.round((25.4 * 8) / 96 * 6) + 1);
        // and the price still comes after it
        expect(calls.find((c) => c.op === 'fillText' && c.text.includes('৳')).y).toBeGreaterThan(code.y);
    });

    it('draws no code line when the shop turned the number off', () => {
        const calls = draw({ settings: { ...SETTINGS, show_barcode_number: false } }).calls;
        expect(calls.some((c) => c.op === 'fillText' && c.font.includes('monospace'))).toBe(false);
    });
});
