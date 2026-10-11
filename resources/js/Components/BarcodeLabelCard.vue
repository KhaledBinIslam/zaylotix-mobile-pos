<script setup>
import { onMounted, watch } from 'vue';

/** One printable barcode label card — shared by BarcodeLabels/Index.vue's real print run and Settings/ReceiptLabel.vue's live preview, so they can never visually drift apart. */
const props = defineProps({
    label: { type: Object, required: true }, // { name, variantLabel, barcode, price, discount_price }
    shop: { type: Object, default: null },
    settings: { type: Object, required: true }, // Shop::LABEL_SETTINGS_DEFAULTS-shaped
    domId: { type: String, required: true }, // unique id for this card's <svg> — caller guarantees uniqueness across a print run
});

async function render() {
    if (!props.label.barcode) return;
    const { default: JsBarcode } = await import('jsbarcode');
    const el = document.getElementById(props.domId);
    if (!el) return;
    try {
        JsBarcode(el, props.label.barcode, { format: 'CODE128', width: 1, height: 22, fontSize: 8, margin: 2, displayValue: props.settings.show_barcode_number !== false });
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
