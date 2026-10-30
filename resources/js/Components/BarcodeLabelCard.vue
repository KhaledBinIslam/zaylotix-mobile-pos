<script setup>
import { onMounted, watch, ref, computed, nextTick } from 'vue';
import { labelSizeMm } from '@/support/labelSizes';
import { thermalModuleWidth, mmToPx } from '@/support/barcodeFit';

/** One printable barcode label card — shared by BarcodeLabels/Index.vue's real print run and Settings/ReceiptLabel.vue's live preview, so they can never visually drift apart. */
const props = defineProps({
    label: { type: Object, required: true }, // { name, variantLabel, barcode, scanCode, price, discount_price }
    shop: { type: Object, default: null },
    settings: { type: Object, required: true }, // Shop::LABEL_SETTINGS_DEFAULTS-shaped
    domId: { type: String, required: true }, // unique id for this card's <svg> — caller guarantees uniqueness across a print run
});

// Phase 43 (docs/BT_PRINT_PLAN.md) — fired once this card's barcode + header
// have actually finished drawing, so a caller doing a bulk html2canvas
// capture (the Bluetooth-print batch) knows precisely when it's safe to
// snapshot this card instead of guessing with a fixed delay.
const emit = defineEmits(['rendered']);

const headerFontSize = ref(14);
const headerEl = ref(null);

const headerText = computed(() => props.shop?.resolved_label_header || props.shop?.name || '');

// Phase 41 (docs/LABEL_PRINT_PLAN.md) — thermal-roll mode: fixed whole-dot
// barcode sizing for the XP-365B instead of the fill-to-width scale below.
// 'classic' (the default for every shop) keeps the old path byte-identical.
const thermal = computed(() => props.settings.print_mode === 'thermal');
const barcodeWarn = ref(''); // '' | 'onedot' | 'overflow'

/**
 * Shrink-to-fit for the sticker header — mirrors render()'s own two-pass
 * JsBarcode width-measurement pattern below, but for text instead of SVG
 * bars. headerEl is a per-instance template ref (not a document-wide
 * selector), since a bulk print run renders many BarcodeLabelCards on the
 * same page at once and a shared `[data-barcode-header]` selector would
 * always resize the FIRST label on the page, not this one.
 */
async function fitHeaderText() {
    if (!props.settings.show_shop_name || !headerText.value || !headerEl.value) {
        headerFontSize.value = 14;
        return;
    }

    const container = headerEl.value.closest('.label-card');
    if (!container) return;

    const availableWidth = container.clientWidth - 12;
    // thermal: start at ~8pt so the 25mm height budget holds (the shrink
    // loop below still applies for a very long business name)
    let size = thermal.value ? 11 : 14;
    headerFontSize.value = size;
    headerEl.value.style.fontSize = size + 'px';

    await nextTick();

    // iterative, not a single division — a scaled-by-formula guess can still
    // overshoot slightly (font rendering isn't perfectly linear with size),
    // and the label must NEVER clip this text, so keep shrinking until it
    // genuinely fits or hits a floor too small to usefully shrink further
    while (headerEl.value.scrollWidth > availableWidth && size > 4) {
        size -= 1;
        headerEl.value.style.fontSize = size + 'px';
    }
    headerFontSize.value = size;
}

/**
 * docs/ARTICLE_SERIES_PLAN.md §10 — live scan failure, measured and
 * confirmed: a color/variant barcode's full descriptive text (the client's
 * explicit requirement — it stays on the label exactly as before) is
 * physically too long to print at a scannable module width on Khaled's
 * fixed-size label stock, no matter how the available width is used (a
 * dynamic fill-width pass alone, §9, wasn't enough — see the chat for the
 * measurements). So what the BARS actually encode (scanCode, e.g.
 * "ZV4821") is now deliberately a short id-derived code, decoupled from
 * what's PRINTED AS TEXT below them (label.barcode, unchanged) via
 * JsBarcode's own `text` option — encoding and the displayed string are
 * independent by design in that library, not a hack. Falls back to
 * encoding label.barcode itself when scanCode isn't given (the plain
 * product-level label, whose own barcode was already short before this
 * and was never the reported problem).
 *
 * The fill-width pass from §9 still applies on top of this — now against
 * the much shorter scanCode, so a label's bars end up both short AND as
 * thick as the label's own width allows.
 */
async function render() {
    if (!props.label.barcode) return;
    const { default: JsBarcode } = await import('jsbarcode');
    const el = document.getElementById(props.domId);
    if (!el) return;
    const displayValue = props.settings.show_barcode_number !== false;
    const encoded = props.label.scanCode || props.label.barcode;
    const opts = { format: 'CODE128', height: 22, fontSize: 8, margin: 2, displayValue, text: props.label.barcode };

    // Phase 41 thermal mode — a fixed whole-dot module width (2 dots =
    // 0.25mm at 203dpi, 1 dot if the value is long), NEVER the fractional
    // fill-to-width scale of the classic branch below: fractional module
    // widths rasterize to uneven bars on a thermal head and scanning dies.
    // Bars ~8mm tall per the 25mm height budget (docs/LABEL_PRINT_PLAN.md).
    if (thermal.value) {
        const usableMm = labelSizeMm(props.settings).width - 5; // 2×2.5mm quiet zone target
        const fit = thermalModuleWidth(encoded, usableMm);
        barcodeWarn.value = fit.fits ? (fit.twoDot ? '' : 'onedot') : 'overflow';
        if (!fit.fits) {
            el.replaceChildren(); // warning text below takes the label instead
            return;
        }
        try {
            JsBarcode(el, encoded, { ...opts, margin: 0, height: mmToPx(8), width: mmToPx(fit.moduleMm) });
        } catch (e) { /* invalid barcode value for CODE128 — leave blank */ }
        return;
    }

    try {
        JsBarcode(el, encoded, { ...opts, width: 1 });
        const naturalWidth = parseFloat(el.getAttribute('width')) || 0;
        const availableWidth = el.closest('.label-card')?.clientWidth - 12; // minus the card's own 6px+6px padding
        if (naturalWidth > 0 && availableWidth > 0) {
            const scale = Math.min(availableWidth / naturalWidth, 2.5);
            JsBarcode(el, encoded, { ...opts, width: scale });
        }
    } catch (e) { /* invalid barcode value for CODE128 — leave blank */ }
}

onMounted(async () => {
    await render();
    await nextTick();
    await fitHeaderText();
    emit('rendered');
});

watch(() => [props.label.barcode, props.settings.show_barcode_number, props.settings.print_mode], render);
watch(() => [props.shop?.resolved_label_header, props.shop?.name, props.settings.show_shop_name], async () => {
    await nextTick();
    fitHeaderText();
}, { flush: 'post' });
</script>

<template>
    <div class="label-card" :class="{ thermal }">
        <div v-if="settings.show_shop_name && headerText" class="label-head">
            <div ref="headerEl" class="label-shop" :style="{ fontSize: headerFontSize + 'px' }">{{ headerText }}</div>
        </div>
        <div v-if="settings.show_product_name" class="label-name">{{ label.name }}</div>
        <div v-if="settings.show_variant && label.variantLabel" class="label-variant">{{ label.variantLabel }}</div>
        <!-- Phase 41 — never shrink a too-long barcode silently: say so.
             Screen-only (no-print): the preview is a mandatory tap before
             printing, so the warning is always seen before paper is used. -->
        <div v-if="thermal && barcodeWarn === 'overflow'" class="label-warn no-print">⚠️ বারকোডটি এই লেবেলের জন্য খুব লম্বা — ছাপা হয়নি</div>
        <div v-else-if="thermal && barcodeWarn === 'onedot'" class="label-warn no-print">⚠️ লম্বা বারকোড — সরু বারে ছাপা হবে, স্ক্যান টেস্ট করে নিন</div>
        <svg :id="domId"></svg>
        <div v-if="settings.price_mode !== 'none'" class="label-price">
            <template v-if="settings.price_mode === 'both' && label.discount_price">
                <span class="label-price-reg">নিয়মিত ৳{{ Math.round(label.price) }}</span>
                <b class="label-price-disc">ছাড় ৳{{ Math.round(label.discount_price) }}</b>
            </template>
            <b v-else class="label-price-disc">৳{{ Math.round(label.discount_price || label.price) }}</b>
        </div>
    </div>
</template>

<style>
.label-card { border: 1px dashed #999; border-radius: 6px; padding: 6px; text-align: center; overflow: hidden; box-sizing: border-box; display: flex; flex-direction: column; align-items: center; justify-content: center; }
.label-head { display: flex; align-items: center; justify-content: center; margin-bottom: 1px; width: 100%; }
.label-shop { font-weight: 900; color: #000; white-space: nowrap; line-height: 1.1; max-width: 100%; }
.label-name { font-size: 9px; font-weight: 700; margin-bottom: 1px; color: #000; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.label-variant { font-size: 8px; font-weight: 600; color: #333; margin-bottom: 1px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.label-price { font-size: 12px; color: #000; margin-top: 1px; display: flex; flex-direction: column; align-items: center; gap: 0; line-height: 1.15; }
.label-price-reg { font-size: 8px; color: #888; text-decoration: line-through; }
.label-price-disc { font-size: 12px; }
.label-card svg { display: block; margin: 1px auto; max-width: 100%; }

/* Phase 41 — thermal-roll mode: everything pure black (grey fades on a
   thermal head), tight line-height so all six lines fit the 25mm budget.
   Classic mode's rules above stay untouched for every other shop. */
.label-card.thermal { line-height: 1; }
.label-card.thermal .label-variant { color: #000; }
.label-card.thermal .label-price-reg { color: #000; }
.label-card.thermal svg { max-width: none; }
.label-warn { font-size: 8px; font-weight: 700; color: #c0392b; }
</style>
