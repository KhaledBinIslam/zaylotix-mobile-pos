<script setup>
import { Head, router } from '@inertiajs/vue3';
import { ref, nextTick, watch, computed } from 'vue';
import AppLayout from '@/Layouts/AppLayout.vue';
import { useI18n } from '@/composables/useI18n';

const props = defineProps({ products: Array, shop: Object });
const { t } = useI18n();

const money = (n) => '৳' + Math.round(n).toLocaleString('en-IN');

// prefilled from ?q=... — the 🏷️ shortcut next to a variant's stock-in
// button on the Stock page links here with the product's own name, so
// printing a label for stock that JUST arrived doesn't need re-typing a
// search by hand
const q = ref(new URLSearchParams(window.location.search).get('q') || '');
// keyed 'p:<productId>' for a plain product, or 'v:<variantId>' for one of
// its variants — a single flat map is simpler to reason about than nesting
// selection state inside props.products itself
const selected = ref({}); // { key: copies }

function variantLabel(v) {
    return [v.size, v.color].filter(Boolean).join(', ');
}

// 'both' (regular + discount, struck-through/bold pair — the old always-on
// behavior), 'single' (just one price — discount price if the product has
// one, else regular), or 'none' (no price line at all). A print-time
// choice, not a shop setting, so it's remembered in localStorage rather
// than sent to the server — same reasoning as any other "how I like to
// print" preference (label size, etc. already work this way elsewhere).
const priceMode = ref(localStorage.getItem('zx_barcode_price_mode') || 'both');
watch(priceMode, (v) => localStorage.setItem('zx_barcode_price_mode', v));

function toggleProduct(p) {
    const key = 'p:' + p.id;
    if (selected.value[key]) delete selected.value[key];
    else selected.value[key] = 1;
}
function toggleVariant(v) {
    if (!v.barcode) return; // generate one first — see generateBarcode()
    const key = 'v:' + v.id;
    if (selected.value[key]) delete selected.value[key];
    else selected.value[key] = 1;
}
/** One tap selects every variant of a product that already has a barcode (1 copy each) — the common "just arrived, print the whole size/color run" case. Variants still missing a barcode are skipped; generate those individually first. */
function selectAllVariants(p) {
    const allSelected = p.variants.every((v) => !v.barcode || selected.value['v:' + v.id]);
    p.variants.forEach((v) => {
        const key = 'v:' + v.id;
        if (!v.barcode) return;
        if (allSelected) delete selected.value[key];
        else selected.value[key] = 1;
    });
}

function generateBarcode(v) {
    router.patch(route('app.productVariants.generateBarcode', v.id), {}, { preserveScroll: true, preserveState: true });
}

const filtered = computed(() => props.products.filter((p) =>
    !q.value
    || p.name.toLowerCase().includes(q.value.toLowerCase())
    || p.name_en?.toLowerCase().includes(q.value.toLowerCase())
    || (p.barcode || '').includes(q.value)
    || p.variants?.some((v) => (v.barcode || '').includes(q.value))
));

const totalCopies = computed(() => Object.values(selected.value).reduce((a, b) => a + b, 0));

const labels = ref([]); // flattened list of {name, variantLabel, barcode, price, discount_price} for print view
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
        const productCopies = selected.value['p:' + p.id];
        if (productCopies) {
            for (let i = 0; i < productCopies; i++) {
                list.push({ name: p.name, variantLabel: '', barcode: p.barcode, price: p.price, discount_price: p.discount_price });
            }
        }
        for (const v of p.variants || []) {
            const copies = selected.value['v:' + v.id];
            if (!copies) continue;
            for (let i = 0; i < copies; i++) {
                list.push({ name: p.name, variantLabel: variantLabel(v), barcode: v.barcode, price: v.price ?? p.price, discount_price: null });
            }
        }
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

        <div class="field" style="margin-bottom:14px">
            <label>{{ t('bc.priceModeLabel') }}</label>
            <div class="seg">
                <button :class="{ on: priceMode === 'both' }" @click="priceMode = 'both'">{{ t('bc.priceModeBoth') }}</button>
                <button :class="{ on: priceMode === 'single' }" @click="priceMode = 'single'">{{ t('bc.priceModeSingle') }}</button>
                <button :class="{ on: priceMode === 'none' }" @click="priceMode = 'none'">{{ t('bc.priceModeNone') }}</button>
            </div>
        </div>

        <div v-for="p in filtered" :key="p.id" class="card" style="padding:0;margin-bottom:8px;overflow:hidden">
            <!-- plain (non-variant) product row — unchanged from before -->
            <div v-if="!p.variants?.length" class="row" @click="toggleProduct(p)" :class="{ incart: selected['p:' + p.id] }" :style="selected['p:' + p.id] ? 'border:none;background:var(--goldSoft)' : 'border:none'">
                <div class="ava">{{ p.emoji }}</div>
                <div class="mid">
                    <b>{{ p.name }}</b>
                    <span>{{ p.barcode || t('bc.noBarcode') }} • {{ money(p.price) }}<span v-if="p.discount_price"> → {{ money(p.discount_price) }}</span></span>
                </div>
                <div class="end" @click.stop>
                    <input v-if="selected['p:' + p.id]" v-model.number="selected['p:' + p.id]" type="number" min="1" style="width:60px;padding:6px;text-align:center">
                </div>
            </div>

            <!-- variant product — every size/color shown underneath, each with its own barcode/copies -->
            <template v-else>
                <div class="row" style="border:none;background:var(--surface2, var(--card))">
                    <div class="ava">{{ p.emoji }}</div>
                    <div class="mid">
                        <b>{{ p.name }}</b>
                        <span>{{ t('bc.variantCount', { n: p.variants.length }) }}</span>
                    </div>
                    <div class="end" @click.stop>
                        <button class="btn sm ghost" style="width:auto;padding:6px 10px" @click="selectAllVariants(p)">{{ t('bc.selectAllVariants') }}</button>
                    </div>
                </div>
                <div v-for="v in p.variants" :key="v.id" class="row" style="border-top:1px solid var(--line);padding-left:20px" :class="{ incart: selected['v:' + v.id] }" :style="selected['v:' + v.id] ? 'background:var(--goldSoft)' : ''" @click="toggleVariant(v)">
                    <div class="ava" style="font-size:16px">🏷️</div>
                    <div class="mid">
                        <b>{{ variantLabel(v) }}</b>
                        <span v-if="v.barcode">{{ v.barcode }} • {{ money(v.price ?? p.price) }}</span>
                        <span v-else style="color:var(--rose)">{{ t('bc.noBarcode') }}</span>
                    </div>
                    <div class="end" @click.stop>
                        <button v-if="!v.barcode" class="btn sm ghost" style="width:auto;padding:6px 10px" @click="generateBarcode(v)">{{ t('bc.generateBarcode') }}</button>
                        <input v-else-if="selected['v:' + v.id]" v-model.number="selected['v:' + v.id]" type="number" min="1" style="width:60px;padding:6px;text-align:center">
                    </div>
                </div>
            </template>
        </div>
        <div v-if="!filtered.length" class="empty"><div class="big">🏷️</div>{{ t('bc.noProducts') }}</div>

        <div style="height:78px"></div>
        <div class="posbar">
            <button class="btn" :disabled="!totalCopies" @click="printSelected">
                {{ t('bc.print') }} ({{ totalCopies }})
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
                        <div v-if="p.variantLabel" class="label-variant">{{ p.variantLabel }}</div>
                        <svg :id="'barcode-svg-' + i"></svg>
                        <div v-if="priceMode !== 'none'" class="label-price">
                            <template v-if="priceMode === 'both' && p.discount_price">
                                <span class="label-price-reg">নিয়মিত ৳{{ Math.round(p.price) }}</span>
                                <b class="label-price-disc">ছাড় ৳{{ Math.round(p.discount_price) }}</b>
                            </template>
                            <b v-else class="label-price-disc">৳{{ Math.round(p.discount_price || p.price) }}</b>
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
.label-logo { width: 9px; height: 9px; object-fit: contain; flex: 0 0 auto; }
.label-shop { font-size: 7px; font-weight: 700; color: #444; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.label-name { font-size: 9px; font-weight: 700; margin-bottom: 1px; color: #000; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.label-variant { font-size: 8px; font-weight: 600; color: #333; margin-bottom: 1px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
/* the price line used to sit right at (sometimes past) the fixed 25mm
   label's bottom edge — with .label-card's overflow:hidden that meant the
   price could get silently clipped off print, invisible with no error.
   Tightened every line above it (logo/name/gaps) so there's always real
   room left for whichever price line priceMode ends up rendering. */
.label-price { font-size: 12px; color: #000; margin-top: 1px; display: flex; flex-direction: column; align-items: center; gap: 0; line-height: 1.15; }
.label-price-reg { font-size: 8px; color: #888; text-decoration: line-through; }
.label-price-disc { font-size: 12px; }
.label-card svg { display: block; margin: 1px auto; }

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
