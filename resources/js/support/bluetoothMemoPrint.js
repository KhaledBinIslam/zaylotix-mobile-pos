// Bluetooth receipt/memo printing via the Zaylotix Printer helper app —
// the same share-an-image pipeline BarcodeLabels uses (Phase 43), but for
// 80mm/58mm continuous receipt paper: gapMm 0 puts the printer in
// continuous mode, so none of the label-roll gap/positioning concerns
// apply; the receipt is simply as long as its content.

import { canShareLabelFiles } from '@/support/bluetoothPrintShare';

/**
 * Printable dot width for the shop's configured memo paper. 203dpi head =
 * 8 dots/mm; 80mm paper has ~72mm printable (576 dots), 58mm has ~48mm
 * (384 dots) — standard for this printer class.
 */
export function memoPrintWidthDots(paperWidth) {
    return String(paperWidth || '').trim() === '80mm' ? 576 : 384;
}

/** The printer-helper's EXTRA_TEXT JSON — same shape ShareReceiverActivity::parseSizeMm reads. */
export function buildMemoShareMetadata(widthDots, heightDots) {
    return JSON.stringify({
        widthMm: widthDots / 8,
        heightMm: Math.round((heightDots / 8) * 10) / 10,
        gapMm: 0,
    });
}

export function canBluetoothPrintMemo() {
    return canShareLabelFiles();
}

/**
 * Captures the rendered memo DOM, scales it (aspect-preserved) to the
 * paper's exact dot width, and hands it to the Android share sheet.
 * Throws on failure; an AbortError means the user closed the share sheet.
 *
 * `barcodeValue` (the memo's CODE128 value, e.g. 'INV-123'): when given,
 * the capture's rasterized barcode is whited out and re-rendered directly
 * on the final canvas at a whole number of printer dots per module — the
 * same fix BarcodeLabels/Index.vue's label path needed, because a resized
 * raster barcode prints uneven bar widths and won't scan (live-confirmed
 * on the XP-365B for both labels and memos).
 */
export async function printMemoViaBluetooth(memoEl, paperWidth, barcodeValue = null) {
    const { default: html2canvas } = await import('html2canvas');
    const raw = await html2canvas(memoEl, { backgroundColor: '#ffffff', scale: 3 });

    const targetW = memoPrintWidthDots(paperWidth);
    const targetH = Math.max(1, Math.round(raw.height * (targetW / raw.width)));
    const fixed = document.createElement('canvas');
    fixed.width = targetW;
    fixed.height = targetH;
    const ctx = fixed.getContext('2d');
    ctx.fillStyle = '#ffffff';
    ctx.fillRect(0, 0, targetW, targetH);
    ctx.drawImage(raw, 0, 0, targetW, targetH);

    const svgEl = barcodeValue ? memoEl.querySelector('svg[id^="memo-barcode-svg"]') : null;
    if (svgEl && svgEl.childNodes.length) {
        const { default: JsBarcode } = await import('jsbarcode');
        const bc = document.createElement('canvas');
        try {
            // width: 2 dots/module — 'INV-<id>' is short enough to fit even
            // the 48mm printable width of 58mm paper at 2 dots comfortably
            JsBarcode(bc, barcodeValue, { format: 'CODE128', width: 2, height: 60, margin: 0, fontSize: 18, displayValue: true });
        } catch (e) { /* unexpected value — keep the captured fallback */ }
        if (bc.width > 0 && bc.width <= targetW) {
            const rootRect = memoEl.getBoundingClientRect();
            const svgRect = svgEl.getBoundingClientRect();
            const s = targetW / rootRect.width;
            const destY = Math.max(0, Math.round((svgRect.top - rootRect.top) * s));
            const clearH = Math.max(Math.round(svgRect.height * s), bc.height) + 4;
            ctx.fillStyle = '#ffffff';
            ctx.fillRect(0, Math.max(0, destY - 2), targetW, clearH);
            ctx.imageSmoothingEnabled = false;
            ctx.drawImage(bc, Math.round((targetW - bc.width) / 2), destY);
        }
    }

    const blob = await new Promise((resolve) => fixed.toBlob(resolve, 'image/png'));
    if (!blob) throw new Error('মেমোর ছবি তৈরি করা যায়নি।');

    const files = [new File([blob], 'memo.png', { type: 'image/png' })];
    if (!navigator.canShare({ files })) throw new Error('এই ব্রাউজারে ফাইল শেয়ার সাপোর্ট নেই।');
    await navigator.share({ files, text: buildMemoShareMetadata(targetW, targetH), title: 'Zaylotix মেমো প্রিন্ট' });
}
