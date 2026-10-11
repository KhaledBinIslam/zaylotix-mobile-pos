// Common thermal barcode-label roll sizes sold/used by small BD shops,
// shared between BarcodeLabels/Index.vue (real print) and
// Settings/ReceiptLabel.vue (live preview) so both resolve a size the
// exact same way.
export const LABEL_SIZE_PRESETS = {
    '38x25': { width: 38, height: 25, label: '38 × 25mm' },
    '50x25': { width: 50, height: 25, label: '50 × 25mm' },
    '40x30': { width: 40, height: 30, label: '40 × 30mm' },
};

export function labelSizeMm(settings) {
    if (settings?.size === 'custom' && settings.custom_width_mm && settings.custom_height_mm) {
        return { width: settings.custom_width_mm, height: settings.custom_height_mm };
    }
    return LABEL_SIZE_PRESETS[settings?.size] || LABEL_SIZE_PRESETS['38x25'];
}
