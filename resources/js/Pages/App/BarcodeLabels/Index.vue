<script setup>
import { Head } from '@inertiajs/vue3';
import { ref, nextTick, watch } from 'vue';
import AppLayout from '@/Layouts/AppLayout.vue';
import { useI18n } from '@/composables/useI18n';

const props = defineProps({ products: Array, shop: Object });
const { t } = useI18n();

const money = (n) => '৳' + Math.round(n).toLocaleString('en-IN');

const q = ref('');
const selected = ref({}); // { productId: copies }

function toggle(p) {
    if (selected.value[p.id]) delete selected.value[p.id];
    else selected.value[p.id] = 1;
}

const filtered = () => props.products.filter((p) =>
    !q.value
    || p.name.toLowerCase().includes(q.value.toLowerCase())
    || p.name_en?.toLowerCase().includes(q.value.toLowerCase())
    || (p.barcode || '').includes(q.value)
);

const labels = ref([]); // flattened list of {product, copyIndex} for print view
const printing = ref(false);

// One tap does select → build → print — a shop owner printing a barcode
// for a single just-arrived product (the overwhelmingly common case)
// shouldn't need a separate "generate" tap before "print" for something
// that's really one action. The preview overlay still opens and stays open
// afterward, so nothing is printed blind — its own Print button (doPrint)
// is there to reprint/adjust without re-selecting from the full list.
async function printSelected() {
    const list = [];
    for (const p of props.products) {
        const copies = selected.value[p.id];
        if (!copies) continue;
        for (let i = 0; i < copies; i++) list.push(p);
    }
    if (!list.length) return;
    labels.value = list;
    printing.value = true;
    await nextTick();
    await renderBarcodes();
    doPrint();
}

async function renderBarcodes() {
    const { default: JsBarcode } = await import('jsbarcode');
    labels.value.forEach((p, i) => {
        const el = document.getElementById('barcode-svg-' + i);
        if (el && p.barcode) {
            try {
                // sized to fit a standard 38×25mm thermal barcode label —
                // the roll size actually sold/used by small BD shops
                JsBarcode(el, p.barcode, { format: 'CODE128', width: 1, height: 22, fontSize: 8, margin: 2 });
            } catch (e) { /* invalid barcode value for CODE128 — leave blank */ }
        }
    });
}

function doPrint() {
    window.print();
}

function closePrint() {
    printing.value = false;
    labels.value = [];
}
</script>

<template>
    <Head :title="t('nav.barcode')" />
    <AppLayout active="more">
        <div class="pgttl">🏷️ {{ t('nav.barcode') }}</div>
        <div class="pgsub">{{ t('bc.subtitle') }}</div>

        <input v-model="q" :placeholder="t('bc.searchPlaceholder')" style="margin-bottom:12px">

        <div v-for="p in filtered()" :key="p.id" class="row" @click="toggle(p)" :class="{ incart: selected[p.id] }" :style="selected[p.id] ? 'border-color:var(--gold);background:var(--goldSoft)' : ''">
            <div class="ava">{{ p.emoji }}</div>
            <div class="mid">
                <b>{{ p.name }}</b>
                <span>{{ p.barcode || t('bc.noBarcode') }} • {{ money(p.price) }}<span v-if="p.discount_price"> → {{ money(p.discount_price) }}</span></span>
            </div>
            <div class="end" @click.stop>
                <input v-if="selected[p.id]" v-model.number="selected[p.id]" type="number" min="1" style="width:60px;padding:6px;text-align:center">
            </div>
        </div>
        <div v-if="!filtered().length" class="empty"><div class="big">🏷️</div>{{ t('bc.noProducts') }}</div>

        <div style="height:78px"></div>
        <div class="posbar">
            <button class="btn" :disabled="!Object.keys(selected).length" @click="printSelected">
                {{ t('bc.print') }} ({{ Object.values(selected).reduce((a, b) => a + b, 0) }})
            </button>
        </div>

        <!-- print view -->
        <Teleport to="body">
            <div v-if="printing" id="label-print-overlay">
                <div class="no-print" style="padding:12px;display:flex;gap:10px;background:#fff;border-bottom:1px solid #ddd">
                    <button class="btn sm" @click="doPrint">{{ t('bc.print') }}</button>
                    <button class="btn sm ghost" @click="closePrint">{{ t('bc.close') }}</button>
                </div>
                <div class="label-grid">
                    <div v-for="(p, i) in labels" :key="i" class="label-card">
                        <div class="label-head">
                            <img v-if="shop?.logo_url" :src="shop.logo_url" class="label-logo">
                            <div class="label-shop">{{ shop?.name }}</div>
                        </div>
                        <div class="label-name">{{ p.name }}</div>
                        <svg :id="'barcode-svg-' + i"></svg>
                        <div class="label-price">
                            <template v-if="p.discount_price">
                                <span class="label-price-reg">নিয়মিত ৳{{ Math.round(p.price) }}</span>
                                <b class="label-price-disc">ছাড় ৳{{ Math.round(p.discount_price) }}</b>
                            </template>
                            <b v-else class="label-price-disc">৳{{ Math.round(p.price) }}</b>
                        </div>
                    </div>
                </div>
            </div>
        </Teleport>
    </AppLayout>
</template>

<style>
#label-print-overlay { position: fixed; inset: 0; background: #fff; z-index: 200; overflow-y: auto; }
.label-grid { display: grid; grid-template-columns: repeat(2, 1fr); gap: 8px; padding: 12px; }
.label-card { border: 1px dashed #999; border-radius: 6px; padding: 6px; text-align: center; overflow: hidden; }
.label-head { display: flex; align-items: center; justify-content: center; gap: 3px; }
.label-logo { width: 10px; height: 10px; object-fit: contain; flex: 0 0 auto; }
.label-shop { font-size: 8px; font-weight: 700; color: #444; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.label-name { font-size: 10px; font-weight: 700; margin-bottom: 2px; color: #000; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.label-price { font-size: 13px; color: #000; margin-top: 2px; display: flex; flex-direction: column; align-items: center; gap: 1px; }
.label-price-reg { font-size: 9px; color: #888; text-decoration: line-through; }
.label-price-disc { font-size: 13px; }
.label-card svg { display: block; margin: 2px auto; }

/*
 * The real bug (found from a real "21 sheets of paper, all blank" print
 * preview): the old `body * { visibility: hidden }` trick hides elements
 * visually but does NOT remove them from the page's layout flow — the
 * entire app UI (sidebar, product list, everything under #app) stayed in
 * the document, invisible but still occupying its full height. With
 * `@page` set to a tiny label height, that invisible full-length app UI
 * got sliced into dozens of near-empty "pages", and the one printed label
 * ended up on just one of them.
 *
 * Fix: Inertia mounts the whole app at `#app` (see resources/views/app.blade.php).
 * The label overlay below is <Teleport to="body">, so it's a sibling of
 * #app, not a child — hiding #app with `display: none` (which DOES remove
 * it from layout) leaves nothing behind to paginate except the actual
 * labels, so no visibility tricks or position overrides are needed at all.
 */
@media print {
    #app { display: none !important; }

    /* one physical label per printed page — 38×25mm is the roll size
       actually sold and used for barcode label printers by small BD shops
       (not the 40×30mm this used to assume). If printing on a normal
       printer/PDF instead, the print dialog's own paper size is used and
       each label just prints in the top-left corner of that page — still
       correct, just not as space-efficient as a real label roll. */
    @page { size: 38mm 25mm; margin: 0; }
    .label-grid { display: block; padding: 0; }
    .label-card {
        width: 38mm; height: 25mm; box-sizing: border-box;
        border: none; border-radius: 0; padding: 1.5mm;
        page-break-after: always; break-after: page;
        display: flex; flex-direction: column; align-items: center; justify-content: center;
    }
    .label-card:last-child { page-break-after: auto; break-after: auto; }
}
</style>
