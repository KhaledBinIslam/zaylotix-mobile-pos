/**
 * One camera-scanner setup, shared by all three places that open one.
 *
 * Khaled: "camera dhorle aro first jate scan hoi". Two things made
 * every frame expensive:
 *
 *   - the decoder was handed 1920x1080. That is four times the pixels of
 *     720p, on a phone, for a barcode held a hand's width from the lens
 *     where 720p already resolves the bars far better than they need.
 *   - with no format list it tries EVERY symbology it knows on each
 *     frame, nearly all of which a shoe shop will never print.
 *
 * The shop's own stickers are CODE_128 (ArticleBarcode builds them);
 * EAN/UPC stay for bought-in goods that arrive already barcoded, and QR
 * because the same sheet is used for payment codes. The rest are
 * dropped, so a frame costs a fraction of what it did and fps 15 is
 * actually reachable instead of aspirational.
 *
 * Kept here because the POS, the clothing POS and the smart-scan sheet
 * each built their own copy, and three copies of a tuning decision drift
 * apart the first time one of them is touched.
 */
export function scannerFormats(Html5QrcodeSupportedFormats) {
    return [
        Html5QrcodeSupportedFormats.CODE_128,
        Html5QrcodeSupportedFormats.EAN_13,
        Html5QrcodeSupportedFormats.EAN_8,
        Html5QrcodeSupportedFormats.UPC_A,
        Html5QrcodeSupportedFormats.UPC_E,
        Html5QrcodeSupportedFormats.QR_CODE,
    ];
}

/** The per-scan options: see this file's own note for why 720p and why 15. */
export function scannerOptions(qrbox) {
    return {
        fps: 15,
        qrbox,
        videoConstraints: { facingMode: 'environment', width: { ideal: 1280 }, height: { ideal: 720 } },
    };
}
