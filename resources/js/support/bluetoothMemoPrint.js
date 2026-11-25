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
 * Gives a step a deadline. Without one, a step that never finishes leaves the
 * Bluetooth button on "Preparing..." forever with no error anywhere — which is
 * exactly what Baby Park's tablet showed (2026-11-25). With one, the screen
 * says WHICH step stalled.
 */
export function withDeadline(promise, ms, what) {
    let timer;
    const timeout = new Promise((_, reject) => {
        timer = setTimeout(() => {
            const e = new Error(`${what} ${Math.round(ms / 1000)} সেকেন্ডেও শেষ হয়নি`);
            e.name = 'MemoStageTimeout';
            reject(e);
        }, ms);
    });

    return Promise.race([promise, timeout]).finally(() => clearTimeout(timer));
}

/**
 * Captures the rendered memo DOM and scales it (aspect-preserved) to the
 * paper's exact dot width, returning the share payload (file + metadata).
 * This is the slow part — do it BEFORE the tap (see useMemoBluetooth).
 *
 * `barcodeValue` (the memo's CODE128 value, e.g. 'INV-123'): when given,
 * the capture's rasterized barcode is whited out and re-rendered directly
 * on the final canvas at a whole number of printer dots per module — the
 * same fix BarcodeLabels/Index.vue's label path needed, because a resized
 * raster barcode prints uneven bar widths and won't scan (live-confirmed
 * on the XP-365B for both labels and memos).
 */
export async function buildMemoShare(memoEl, paperWidth, barcodeValue = null, onStage = () => {}) {
    const targetW = memoPrintWidthDots(paperWidth);

    onStage('ছবির লাইব্রেরি আনা হচ্ছে…');
    const { default: html2canvas } = await withDeadline(import('html2canvas'), 10000, 'ছবির লাইব্রেরি (html2canvas) আনা');

    // Draw at just the size the paper needs (the old fixed 3x made a canvas about 2x bigger than
    // 80mm/58mm paper can use, which a weak tablet may not survive) and skip any picture that does
    // not load within 4s instead of waiting on it.
    const cssW = memoEl.getBoundingClientRect().width || targetW;
    const scale = Math.min(3, Math.max(1, targetW / cssW));
    onStage('বিলের ছবি আঁকা হচ্ছে…');
    const raw = await withDeadline(
        html2canvas(memoEl, { backgroundColor: '#ffffff', scale, imageTimeout: 4000, logging: false }),
        15000,
        'বিলের ছবি আঁকা',
    );

    onStage('ছবি তৈরি হচ্ছে…');
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
        const { default: JsBarcode } = await withDeadline(import('jsbarcode'), 10000, 'বারকোড লাইব্রেরি আনা');
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

    const blob = await withDeadline(new Promise((resolve) => fixed.toBlob(resolve, 'image/png')), 8000, 'ছবি সেভ করা');
    if (!blob) throw new Error('মেমোর ছবি তৈরি করা যায়নি।');

    return { files: [new File([blob], 'memo.png', { type: 'image/png' })], text: buildMemoShareMetadata(targetW, targetH), title: 'Zaylotix মেমো প্রিন্ট' };
}

/** Hands a finished receipt to the Android share sheet. Needs the tap's user gesture, so call it with NO slow work before it. */
export async function shareMemoFile(share) {
    if (!navigator.canShare({ files: share.files })) throw new Error('এই ব্রাউজারে ফাইল শেয়ার সাপোর্ট নেই।');
    await navigator.share(share);
}

/** Build + share in one go (kept for callers that have no early build). */
export async function printMemoViaBluetooth(memoEl, paperWidth, barcodeValue = null) {
    await shareMemoFile(await buildMemoShare(memoEl, paperWidth, barcodeValue));
}
