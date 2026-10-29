<script setup>
import { onMounted, watch } from 'vue';

/** One printable barcode label card — shared by BarcodeLabels/Index.vue's real print run and Settings/ReceiptLabel.vue's live preview, so they can never visually drift apart. */
const props = defineProps({
    label: { type: Object, required: true }, // { name, variantLabel, barcode, scanCode, price, discount_price }
    shop: { type: Object, default: null },
    settings: { type: Object, required: true }, // Shop::LABEL_SETTINGS_DEFAULTS-shaped
    domId: { type: String, required: true }, // unique id for this card's <svg> — caller guarantees uniqueness across a print run
});

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
onMounted(render);
watch(() => [props.label.barcode, props.settings.show_barcode_number], render);
</script>

<template>
    <div class="label-card">
        <div v-if="settings.show_shop_name" class="label-head">
            <img v-if="shop?.logo_url" :src="shop.logo_url" class="label-logo">
            <div class="label-shop">{{ shop?.name }}</div>
        </div>
        <div v-if="settings.show_product_name" class="label-name">{{ label.name }}</div>
        <div v-if="settings.show_variant && label.variantLabel" class="label-variant">{{ label.variantLabel }}</div>
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
.label-head { display: flex; align-items: center; justify-content: center; gap: 3px; }
.label-logo { width: 9px; height: 9px; object-fit: contain; flex: 0 0 auto; }
.label-shop { font-size: 7px; font-weight: 700; color: #444; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.label-name { font-size: 9px; font-weight: 700; margin-bottom: 1px; color: #000; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.label-variant { font-size: 8px; font-weight: 600; color: #333; margin-bottom: 1px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.label-price { font-size: 12px; color: #000; margin-top: 1px; display: flex; flex-direction: column; align-items: center; gap: 0; line-height: 1.15; }
.label-price-reg { font-size: 8px; color: #888; text-decoration: line-through; }
.label-price-disc { font-size: 12px; }
.label-card svg { display: block; margin: 1px auto; max-width: 100%; }
</style>
