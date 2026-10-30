import { labelSizeMm } from '@/support/labelSizes';

// Phase 43 (docs/BT_PRINT_PLAN.md বিকল্প ক) — the pure, testable half of
// the "📱 Bluetooth-এ প্রিন্ট" feature. The actual DOM rasterization
// (html2canvas) and navigator.share() call live directly in
// BarcodeLabels/Index.vue since neither is meaningfully unit-testable in
// jsdom; this file only builds the small JSON metadata string the
// printer-helper app's ShareReceiverActivity reads (see its own docblock)
// and the feature-detection check, both plain logic.

/** The exact JSON the printer-helper app expects in the share's `text` field — its own LabelSizeMm parser mirrors this shape. */
export function buildShareMetadata(settings) {
    const size = labelSizeMm(settings);
    return JSON.stringify({
        widthMm: size.width,
        heightMm: size.height,
        gapMm: Number(settings?.label_gap_mm ?? 2),
    });
}

/**
 * True only when this browser can actually share files (not just text) —
 * `navigator.share` existing alone isn't enough, Safari/old Chrome support
 * text-only sharing without file support. Takes `nav` as a parameter
 * (defaults to the real `navigator`) so this is testable with a mock.
 */
export function canShareLabelFiles(nav = typeof navigator !== 'undefined' ? navigator : null) {
    if (!nav || typeof nav.share !== 'function' || typeof nav.canShare !== 'function') return false;
    try {
        // a 1-byte placeholder File is enough to ask canShare() whether
        // file-sharing itself is supported — its own size/content is never
        // actually shared, this call alone doesn't share anything
        const probe = new File([new Uint8Array([0])], 'probe.png', { type: 'image/png' });
        return nav.canShare({ files: [probe] });
    } catch (e) {
        return false;
    }
}
