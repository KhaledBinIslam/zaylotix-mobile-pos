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
export const DRAW_BUILD = 'direct-2';

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

/** Hard-truncates with an ellipsis once shrinking alone cannot make the text fit. */
export function truncateToFit(measure, text, maxWidth) {
    if (measure(text) <= maxWidth) return text;
    let t = String(text);
    while (t.length > 1 && measure(t + '…') > maxWidth) {
        t = t.slice(0, -1);
    }

    return t + '…';
}

/**
 * @param {HTMLCanvasElement} canvas sized to the label in printer dots
 * @param {object} opts
 * @param {object} opts.label  the label row ({ barcode, scanCode, price, discount_price })
 * @param {object} opts.settings  the shop's label settings
 * @param {string} opts.headerText  the shop/business name for the top line
 * @param {HTMLCanvasElement|null} opts.barcodeCanvas  bars already drawn at whole dots, or null
 */
export function drawThermalLabel(canvas, { label, settings, headerText, barcodeCanvas }) {
    // SEAMAN Phase 7 — the 'detailed' template is its own drawing path;
    // the classic path below is UNTOUCHED, byte-for-byte, because Baby
    // Park's live stickers are pinned to it (and to its tests).
    if (settings.template === 'detailed') {
        return drawDetailedLabel(canvas, { label, settings, headerText, barcodeCanvas });
    }

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

/**
 * SEAMAN Phase 7 (round 3, after the first live Xprinter photo) — the
 * 'detailed' sticker. Top to bottom, centred:
 *
 *   seamanstore.com (the EDITABLE label-header setting)  — small
 *   BARCODE (its canvas is pre-built at ~90% label width, ~38% height)
 *   ZV code                                              — small
 *   product name                                         — largest, max 2 lines
 *   Lotus  |  Size: 42  (Brand: Nil when none; size part dropped)
 *   ৳1,250 / Price: Nil                                  — big, at the bottom
 *
 * EVERYTHING scales off the label's own width/height — no fixed pixel
 * sizes, so a 50×30 roll fills exactly like a 38×25 one (the live
 * photo's "content in the top half" was fixed-CSS-px sizing on a label
 * bigger than the app's size setting). Whatever vertical space is left
 * after the blocks becomes EQUAL gaps between them, so there is never
 * a large empty area. Text shrinks and then ellipsises; the bars never
 * lose a dot ("text gives way, never the bars").
 */
export function detailedPriceLines(label, settings) {
    const lines = priceLinesOf(label, settings);
    if (settings.price_mode === 'none') return lines;

    const hasRealPrice = Number(label.price) > 0 || Number(label.discount_price) > 0;

    return hasRealPrice ? lines : [{ text: 'Price: Nil', cssPx: 10, strike: false }];
}

/** The one brand/size line: "Lotus  |  Size: 42", "Brand: Nil", "Brand: Nil  |  Size: L"… */
export function brandSizeLine(label) {
    const parts = [label.brandName || 'Brand: Nil'];
    if (label.variantLabel) parts.push('Size: ' + label.variantLabel);

    return parts.join('  |  ');
}

function drawDetailedLabel(canvas, { label, settings, headerText, barcodeCanvas }) {
    const ctx = canvas.getContext('2d');
    const W = canvas.width;
    const H = canvas.height;

    ctx.fillStyle = '#ffffff';
    ctx.fillRect(0, 0, W, H);
    ctx.fillStyle = '#000000';
    ctx.textAlign = 'center';
    ctx.textBaseline = 'top';

    const padX = Math.round(W * 0.04); // ~2mm side margin on a 50mm label
    const usable = W - padX * 2;

    const setFont = (size) => { ctx.font = `900 ${size}px ${FONT_STACK}`; };
    const fitted = (text, startSize) => {
        const size = fitFontSize((s) => { setFont(s); return ctx.measureText(text).width; }, text, usable, startSize, Math.max(6, Math.round(startSize * 0.5)));
        setFont(size);

        return { text: truncateToFit((t) => ctx.measureText(t).width, text, usable), size };
    };

    // ---- the blocks, each sized as a fraction of the label height ------
    const hasBars = !!(barcodeCanvas && barcodeCanvas.width > 0);
    const priceLines = detailedPriceLines(label, settings);

    // the name gets one line when it fits, a second when it needs it
    const nameSize = Math.round(H * 0.115);
    let nameLines = [];
    if (label.name) {
        setFont(nameSize);
        if (ctx.measureText(label.name).width <= usable) {
            nameLines = [label.name];
        } else {
            // greedy word-wrap onto two lines; the second shrinks/ellipsises
            const words = String(label.name).split(/\s+/);
            let first = '';
            for (const word of words) {
                const attempt = first ? first + ' ' + word : word;
                setFont(nameSize);
                if (ctx.measureText(attempt).width <= usable) first = attempt;
                else break;
            }
            if (!first) first = words[0] ?? '';
            const rest = String(label.name).slice(first.length).trim();
            nameLines = rest ? [first, rest] : [first];
        }
    }

    const blocks = [];
    if (headerText) blocks.push({ kind: 'text', text: headerText, h: Math.round(H * 0.085) });
    if (hasBars) blocks.push({ kind: 'bars', h: barcodeCanvas.height });
    if (hasBars && label.barcode) blocks.push({ kind: 'text', text: String(label.barcode), h: Math.round(H * 0.07) });
    for (const line of nameLines) blocks.push({ kind: 'text', text: line, h: Math.round(H * 0.115) });
    blocks.push({ kind: 'text', text: brandSizeLine(label), h: Math.round(H * 0.085) });
    for (const priceLine of priceLines) {
        blocks.push({ kind: 'text', text: priceLine.text, h: Math.round(H * (priceLine.strike ? 0.09 : 0.15)), strike: priceLine.strike });
    }

    // ---- vertical fit: shrink TEXT (never the bars), then spread the ----
    // ---- leftover height as equal gaps so nothing pools at the bottom --
    const textH = blocks.filter((b) => b.kind === 'text').reduce((sum, b) => sum + b.h, 0);
    const barsH = blocks.filter((b) => b.kind === 'bars').reduce((sum, b) => sum + b.h, 0);
    const minGap = Math.max(2, Math.round(H * 0.012));
    const budget = H - barsH - minGap * (blocks.length + 1);
    const squeeze = textH > budget && textH > 0 ? Math.max(0.3, budget / textH) : 1;
    for (const b of blocks) {
        if (b.kind === 'text') b.h = Math.max(8, Math.round(b.h * squeeze));
    }

    const used = blocks.reduce((sum, b) => sum + b.h, 0);
    const gap = Math.max(minGap, Math.floor((H - used) / (blocks.length + 1)));

    // ---- draw ----------------------------------------------------------
    let y = gap;
    for (const block of blocks) {
        if (block.kind === 'bars') {
            // whole-pixel placement, smoothing off: a bar on half a dot
            // is what stops a printed barcode scanning
            ctx.imageSmoothingEnabled = false;
            const x = Math.max(0, Math.round((W - barcodeCanvas.width) / 2));
            ctx.drawImage(barcodeCanvas, x, y);
        } else {
            const { text, size } = fitted(block.text, Math.round(block.h / 1.1));
            ctx.fillText(text, W / 2, y, usable);
            if (block.strike) {
                const w = Math.min(usable, ctx.measureText(text).width);
                ctx.fillRect(Math.round((W - w) / 2), y + Math.round(size * 0.55), Math.round(w), Math.max(1, Math.round(H * 0.006)));
            }
        }
        y += block.h + gap;
    }

    return canvas;
}
