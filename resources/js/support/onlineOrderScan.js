/**
 * Scan-to-add for the online order form, as one pure decision function so
 * every entry point (camera, hardware scanner, the typed field) takes the
 * same path and the logic is unit-testable without mounting the page.
 *
 * Local match order is exactly what Orders/Create.vue always did: a short
 * ZV{variantId} / ZC{colorBarcodeId} sticker code, then a size's own
 * barcode, then a colour-level barcode, then the article's barcode. Only
 * when nothing local matches is `lookup(code)` asked — the server-side
 * BarcodeResolver, which also resolves a sticker printed at the SIBLING
 * branch (its variant id does not exist in this shop, but its barcode
 * string does; see OnlineOrderController::barcode()). A found product is
 * handed back with `fromServer: true` so the caller can push it into the
 * page's product list, the same way POS does.
 *
 * `typed` input (the manual field) also gets a second, forgiving local
 * pass — case-insensitive, spaces stripped — because a human types
 * "china-s-80001 ..." where a scanner never would. Scanner codes are
 * matched exactly and nothing else.
 *
 * Returns one of:
 *   { type: 'line',     product, variant }           — a size: add the line
 *   { type: 'color',    product, color }             — colour sticker: pick the size
 *   { type: 'picker',   product }                    — article barcode with sizes: pick one
 *   { type: 'line',     product, variant: null }     — plain product: add the line
 *   { type: 'notFound' }
 */
const normalize = (s) => String(s ?? '').toUpperCase().replace(/\s+/g, '');

function matchLocally(products, code, loose) {
    const eq = loose ? (a, b) => normalize(a) === normalize(b) : (a, b) => a === b;

    // short sticker codes are upper-case by construction; only the typed
    // (loose) pass forgives a lower-case "zv…"
    const zv = code.match(loose ? /^ZV(\d+)$/i : /^ZV(\d+)$/);
    if (zv) {
        const vid = Number(zv[1]);
        for (const p of products) {
            const v = p.variants?.find((variant) => variant.id === vid);
            if (v) return { type: 'line', product: p, variant: v };
        }
    }
    const zc = code.match(loose ? /^ZC(\d+)$/i : /^ZC(\d+)$/);
    if (zc) {
        const cid = Number(zc[1]);
        for (const p of products) {
            const cb = p.color_barcodes?.find((c) => c.id === cid);
            if (cb) return { type: 'color', product: p, color: cb.color };
        }
    }
    for (const p of products) {
        const v = p.variants?.find((variant) => variant.barcode && eq(variant.barcode, code));
        if (v) return { type: 'line', product: p, variant: v };
    }
    for (const p of products) {
        const cb = p.color_barcodes?.find((c) => c.barcode && eq(c.barcode, code));
        if (cb) return { type: 'color', product: p, color: cb.color };
    }
    const product = products.find((p) => p.barcode && eq(p.barcode, code));
    if (product) {
        return product.variants?.length ? { type: 'picker', product } : { type: 'line', product, variant: null };
    }
    return null;
}

/**
 * @param {object} args
 * @param {string} args.code            raw scanned/typed text
 * @param {Array}  args.products        the page's product list (with variants / color_barcodes)
 * @param {(code: string) => Promise<{found: boolean, product?: object, variant_id?: number, color?: string}>} args.lookup  server fallback
 * @param {boolean} [args.typed=false]  manual field input (enables the forgiving second pass)
 */
export async function resolveScannedCode({ code, products, lookup, typed = false }) {
    const trimmed = String(code ?? '').trim();
    if (!trimmed) return { type: 'notFound' };

    const local = matchLocally(products, trimmed, false) ?? (typed ? matchLocally(products, trimmed, true) : null);
    if (local) return local;

    let data;
    try {
        data = await lookup(trimmed);
    } catch (e) {
        return { type: 'notFound' };
    }
    if (!data?.found || !data.product) return { type: 'notFound' };

    const product = data.product;
    if (data.variant_id) {
        const variant = product.variants?.find((v) => v.id === data.variant_id) ?? null;
        return variant ? { type: 'line', product, variant, fromServer: true } : { type: 'picker', product, fromServer: true };
    }
    if (data.color) return { type: 'color', product, color: data.color, fromServer: true };
    return product.variants?.length
        ? { type: 'picker', product, fromServer: true }
        : { type: 'line', product, variant: null, fromServer: true };
}
