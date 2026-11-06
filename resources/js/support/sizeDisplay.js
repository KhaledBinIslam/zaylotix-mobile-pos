/**
 * Half sizes are STORED as plain decimals ("20.5") so sorting, barcodes
 * and the database stay ASCII and unambiguous — but the owner's note
 * writes them the way a cap maker does: 20½. Display-only conversion;
 * never feed the output back into a form or a barcode.
 *
 * Anything that isn't exactly <digits>.5 passes through untouched, so
 * every existing shop's sizes ("42", "XL", "20.5 inch" even) render as
 * they always did.
 */
export function formatSize(size) {
    const s = String(size ?? '');
    const m = s.match(/^(\d+)\.5$/);

    return m ? m[1] + '½' : s;
}
