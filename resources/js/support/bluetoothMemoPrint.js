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
 */
export async function printMemoViaBluetooth(memoEl, paperWidth) {
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

    const blob = await new Promise((resolve) => fixed.toBlob(resolve, 'image/png'));
    if (!blob) throw new Error('মেমোর ছবি তৈরি করা যায়নি।');

    const files = [new File([blob], 'memo.png', { type: 'image/png' })];
    if (!navigator.canShare({ files })) throw new Error('এই ব্রাউজারে ফাইল শেয়ার সাপোর্ট নেই।');
    await navigator.share({ files, text: buildMemoShareMetadata(targetW, targetH), title: 'Zaylotix মেমো প্রিন্ট' });
}
