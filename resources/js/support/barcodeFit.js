// Phase 41 (docs/LABEL_PRINT_PLAN.md) — pure CODE128 width math for the
// thermal-roll print mode. Used by BarcodeLabelCard.vue to pick a whole-dot
// module width (never a fractional fill-to-width scale, which makes bars
// uneven at 203dpi and kills scanning) and to flag a value that physically
// can't fit the label at a scannable width.

/** CSS px per mm (CSS defines 1in = 96px, 1in = 25.4mm). */
export const PX_PER_MM = 96 / 25.4;

export const mmToPx = (mm) => mm * PX_PER_MM;

/**
 * Estimated CODE128 module count for a value, matching JsBarcode's auto
 * mode closely enough for layout decisions:
 * - all-digit values pack 2 digits/symbol (Code C), odd lengths cost one
 *   extra symbol;
 * - anything else is one symbol per character (Code B).
 * Structure: start(11) + data symbols×11 + checksum(11) + stop+termination(13).
 */
export function estimateCode128Modules(value) {
    const s = String(value || '');
    if (!s) return 0;
    const dataSymbols = /^\d+$/.test(s) ? Math.ceil(s.length / 2) + (s.length % 2) : s.length;
    return 11 + dataSymbols * 11 + 11 + 13;
}

/**
 * Decide the module width (in mm) for a value on a thermal label.
 * 203dpi → 1 dot = 0.125mm; preferred is 2 dots (0.25mm). Returns
 * { moduleMm, fits } — fits=false means even 1 dot/module overflows the
 * usable width, so the label should show a warning instead of a barcode.
 */
export function thermalModuleWidth(value, usableMm) {
    const modules = estimateCode128Modules(value);
    if (!modules || !usableMm) return { moduleMm: 0.25, fits: true, modules, twoDot: true };
    if (modules * 0.25 <= usableMm) return { moduleMm: 0.25, fits: true, modules, twoDot: true };
    if (modules * 0.125 <= usableMm) return { moduleMm: 0.125, fits: true, modules, twoDot: false };
    return { moduleMm: 0.125, fits: false, modules, twoDot: false };
}
