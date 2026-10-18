<script setup>
import { Head, Link, router } from '@inertiajs/vue3';
import { ref, computed } from 'vue';
import AppLayout from '@/Layouts/AppLayout.vue';
import BarcodeLabelCard from '@/Components/BarcodeLabelCard.vue';
import { useI18n } from '@/composables/useI18n';
import { labelSizeMm } from '@/support/labelSizes';

const props = defineProps({ products: Array, shop: Object });
const { t } = useI18n();

const money = (n) => '৳' + Math.round(n).toLocaleString('en-IN');
const settings = computed(() => props.shop?.label_settings || {});
const sizeMm = computed(() => labelSizeMm(settings.value));

const q = ref(new URLSearchParams(window.location.search).get('q') || '');
const selected = ref({}); // { key: copies }

function variantLabel(v) {
    return [v.size, v.color].filter(Boolean).join(', ');
}

function toggleProduct(p) {
    const key = 'p:' + p.id;
    if (selected.value[key]) delete selected.value[key];
    else selected.value[key] = 1;
}
function toggleVariant(v) {
    if (!v.barcode) return;
    const key = 'v:' + v.id;
    if (selected.value[key]) delete selected.value[key];
    else selected.value[key] = 1;
}
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

const labels = ref([]); // flattened list of {name, variantLabel, barcode, price, discount_price}
const printing = ref(false);

/** Builds the selected list and opens the preview overlay — print itself is now a deliberate second tap (doPrint), not automatic, so there's always a real chance to review before paper gets used. */
function buildAndPreview() {
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
}

function doPrint() {
    window.print();
}

function closePrint() {
    printing.value = false;
    labels.value = [];
}

const printCss = computed(() => `
@page { size: ${sizeMm.value.width}mm ${sizeMm.value.height}mm; margin: 0; }
.label-grid { grid-template-columns: repeat(${settings.value.per_row || 2}, 1fr); }
@media print {
    .label-card { width: ${sizeMm.value.width}mm; height: ${sizeMm.value.height}mm; }
}
`);
</script>

<template>
    <Head :title="t('nav.barcode')" />
    <AppLayout active="more">
        <div class="pgttl">🏷️ {{ t('nav.barcode') }}</div>
        <div class="pgsub">{{ t('bc.subtitle') }}</div>

        <Link :href="route('app.settings.receiptLabel')" class="no-print" style="display:inline-flex;align-items:center;gap:4px;font-size:13px;color:var(--gold2);margin-bottom:10px">
            ⚙️ {{ t('bc.labelSettingsLink') }} ({{ sizeMm.width }}×{{ sizeMm.height }}mm, {{ settings.per_row || 2 }}/{{ t('bc.perRow') }})
        </Link>

        <input v-model="q" :placeholder="t('bc.searchPlaceholder')" style="margin-bottom:12px">

        <div v-for="p in filtered" :key="p.id" class="card" style="padding:0;margin-bottom:8px;overflow:hidden">
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
            <button class="btn" :disabled="!totalCopies" @click="buildAndPreview">
                {{ t('bc.preview') }} ({{ totalCopies }})
            </button>
        </div>

        <!-- print preview — doPrint is now a deliberate second tap, not automatic -->
        <Teleport to="body">
            <div v-if="printing" id="label-print-overlay">
                <component :is="'style'" v-html="printCss"></component>
                <div class="no-print" style="padding:12px;display:flex;gap:10px;background:var(--panel);border-bottom:1px solid var(--line)">
                    <button class="btn sm" @click="doPrint">🖨️ {{ t('bc.print') }}</button>
                    <button class="btn sm ghost" @click="closePrint">{{ t('bc.close') }}</button>
                </div>
                <div class="label-grid">
                    <BarcodeLabelCard
                        v-for="(p, i) in labels" :key="i"
                        :label="p" :shop="shop" :settings="settings" :dom-id="'barcode-svg-' + i"
                    />
                </div>
            </div>
        </Teleport>
    </AppLayout>
</template>

<style>
#label-print-overlay { position: fixed; inset: 0; background: #fff; z-index: 200; overflow-y: auto; }
.label-grid { display: grid; gap: 8px; padding: 12px; }

@media print {
    #app { display: none !important; }
    .label-grid { display: block; padding: 0; }
    .label-card {
        box-sizing: border-box; border: none; border-radius: 0; padding: 1.5mm;
        page-break-after: always; break-after: page;
    }
    .label-card:last-child { page-break-after: auto; break-after: auto; }
}
</style>
