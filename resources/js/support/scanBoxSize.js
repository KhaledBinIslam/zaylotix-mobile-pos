/**
 * docs/ARTICLE_SERIES_PLAN.md §9 — the camera scanner's decode region
 * (html5-qrcode's `qrbox`). Measured against real printed labels: the old
 * fixed 260x200 (near-square) box crops the live camera frame down to
 * exactly that region BEFORE any decoding happens — html5-qrcode draws
 * only that rectangle to its decode canvas (see its own start()
 * implementation). A long Code128 string like the new color barcode
 * (SMS-L-10001-NSXXZ-SIL) is a WIDE, SHORT strip; a square-ish crop
 * routinely clips its ends when a cashier frames the whole sticker
 * naturally, producing a silent decode failure — no error, nothing, the
 * decoder simply never sees the missing part of the barcode.
 *
 * A wide, short box matches a 1D barcode's actual shape instead, while
 * staying well within the viewfinder (html5-qrcode throws if qrbox exceeds
 * it) and never shrinking an old, already-working short barcode's chances.
 */
export function scanBoxSize(viewfinderWidth, viewfinderHeight) {
    return {
        width: Math.floor(Math.min(viewfinderWidth * 0.9, 380)),
        height: Math.floor(Math.min(viewfinderHeight * 0.4, 160)),
    };
}
