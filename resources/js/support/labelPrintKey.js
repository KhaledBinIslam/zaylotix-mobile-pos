/**
 * A key for "these two labels print exactly the same sticker".
 *
 * Bluetooth printing collapses identical labels into ONE image plus a
 * copies count, which the printer repeats itself via TSPL's `PRINT n,1` —
 * one connect and one job for the whole run instead of one per label.
 * That only works if "identical" is judged on what actually gets PRINTED.
 *
 * Keying on the whole label object got this wrong the moment the thermal
 * sticker dropped its product-name and size/colour lines: every size of
 * one colour prints the same sticker, but the objects still carry the
 * size, so nothing collapsed and each size became its own Bluetooth job —
 * eight connects, eight 400ms waits, for eight identical stickers.
 *
 * Mirrors BarcodeLabelCard.vue's own template conditions. Settings that
 * are the same for every label in a run (show_shop_name, the header text)
 * are left out: they cannot tell two labels apart. Anything the card
 * would draw differently MUST be in here, or two different stickers would
 * silently print as copies of one.
 */
export function printedLabelKey(label, settings = {}, thermal = false) {
    const parts = [];

    // the bars encode scanCode when it exists, else the barcode itself
    parts.push(label.scanCode || label.barcode || '');

    // the human-readable number under the bars, only when it is shown
    parts.push(settings.show_barcode_number !== false ? (label.barcode || '') : '');

    // the product-name and size/colour lines exist on a sheet label only
    if (!thermal) {
        parts.push(settings.show_product_name ? (label.name || '') : '');
        parts.push(settings.show_variant ? (label.variantLabel || '') : '');
    }

    if (settings.price_mode !== 'none') {
        parts.push(label.price ?? '');
        parts.push(label.discount_price ?? '');
    }

    return JSON.stringify(parts);
}
