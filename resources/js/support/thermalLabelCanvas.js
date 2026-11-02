/**
 * Draws a thermal sticker straight onto a canvas, at printer resolution.
 *
 * The batch used to photograph each card off the page with html2canvas —
 * clone the DOM, re-parse every style, rasterize at 3x, downscale — and
 * then throw the captured barcode away and redraw it anyway, because a
 * resized barcode does not scan. That costs a few hundred milliseconds
 * per distinct label, which is why a batch of one colour printed and a
 * batch of five did not: on a slow tablet the drawing was still running
 * when the button was tapped, and Android had already expired the tap.
 * Khaled: "joto slow mbl e how print jate first hoi".
 *
 * A thermal sticker is three blocks — shop name, barcode, price — so
 * there is nothing here the Canvas API cannot draw directly, in about a
 * millisecond, with the browser still doing the text layout (which is
 * what keeps a Bengali shop name correct). The barcode is drawn by
 * JsBarcode at exact whole dots, as it already was.
 *
 * The geometry mirrors BarcodeLabelCard's thermal CSS so the printed
 * sticker does not shift: padding 2px/6px, a 900-weight name on a 1.25
 * line box with 5px under it, the bars with 3px under them, then the
 * price. Those are CSS pixels, converted here to printer dots.
 */

/** A CSS pixel is 1/96 inch; the printer lays down 8 dots per mm. */
export const DOTS_PER_CSS_PX = (25.4 * 8) / 96;

/** Build identifier, so a screenshot of the on-screen log proves which bundle a phone is actually running. */
export const DRAW_BUILD = 'direct-1';

const px = (cssPx) => cssPx * DOTS_PER_CSS_PX;

/**
 * Largest font size (in the same unit `measure` works in) at which the
 * text still fits `maxWidth`, never below `min`.
 *
 * Pure, taking its measuring function, so the shrink-to-fit rule can be
 * tested without a canvas — it is the one part of this file with any
 * arithmetic worth pinning.
 */
export function fitFontSize(measure, text, maxWidth, start, min = 1) {
    let size = start;
    while (size > min && measure(size) > maxWidth) {
        size -= 1;
    }

    return Math.max(min, size);
}

/** The price line(s), exactly as BarcodeLabelCard renders them. */
export function priceLinesOf(label, settings) {
    if (settings.price_mode === 'none') return [];

    const round = (n) => Math.round(Number(n) || 0).toString();
    if (settings.price_mode === 'both' && label.discount_price) {
        return [
            { text: 'নিয়মিত ৳' + round(label.price), cssPx: 8, strike: true },
            { text: 'ছাড় ৳' + round(label.discount_price), cssPx: 10, strike: false },
        ];
    }

    return [{ text: '৳' + round(label.discount_price || label.price), cssPx: 10, strike: false }];
}

const FONT_STACK = 'system-ui, -apple-system, "Segoe UI", Roboto, "Noto Sans Bengali", sans-serif';

/**
 * @param {HTMLCanvasElement} canvas sized to the label in printer dots
 * @param {object} opts
 * @param {object} opts.label  the label row ({ barcode, scanCode, price, discount_price })
 * @param {object} opts.settings  the shop's label settings
 * @param {string} opts.headerText  the shop/business name for the top line
 * @param {HTMLCanvasElement|null} opts.barcodeCanvas  bars already drawn at whole dots, or null
 */
export function drawThermalLabel(canvas, { label, settings, headerText, barcodeCanvas }) {
    const ctx = canvas.getContext('2d');
    const W = canvas.width;
    const H = canvas.height;

    ctx.fillStyle = '#ffffff';
    ctx.fillRect(0, 0, W, H);
    ctx.fillStyle = '#000000';
    ctx.textAlign = 'center';
    ctx.textBaseline = 'top';

    const padX = Math.round(px(6));
    const usable = W - padX * 2;
    let y = Math.round(px(2));

    if (settings.show_shop_name && headerText) {
        // 900 weight throughout: thinner strokes come out of the 1-bit
        // threshold broken on this head (live print evidence)
        const size = fitFontSize(
            (s) => {
                ctx.font = `900 ${s}px ${FONT_STACK}`;

                return ctx.measureText(headerText).width;
            },
            headerText,
            usable,
            Math.round(px(11)),
            Math.round(px(5)),
        );
        ctx.font = `900 ${size}px ${FONT_STACK}`;
        ctx.fillText(headerText, W / 2, y, usable);
        y += Math.round(size * 1.25) + Math.round(px(5));
    }

    if (barcodeCanvas && barcodeCanvas.width > 0) {
        // imageSmoothing off and whole-pixel placement: a bar that lands
        // on a half dot is what makes a printed barcode stop scanning
        ctx.imageSmoothingEnabled = false;
        const x = Math.max(0, Math.round((W - barcodeCanvas.width) / 2));
        ctx.drawImage(barcodeCanvas, x, y);
        y += barcodeCanvas.height + Math.round(px(3));
    }

    for (const line of priceLinesOf(label, settings)) {
        const size = Math.round(px(line.cssPx));
        ctx.font = `900 ${size}px ${FONT_STACK}`;
        ctx.fillText(line.text, W / 2, y, usable);

        if (line.strike) {
            const w = Math.min(usable, ctx.measureText(line.text).width);
            const mid = y + Math.round(size * 0.55);
            ctx.fillRect(Math.round((W - w) / 2), mid, Math.round(w), Math.max(1, Math.round(px(1))));
        }

        y += Math.round(size * 1.15);
    }

    return canvas;
}
