<script setup>
import { Head, Link, useForm, usePage } from '@inertiajs/vue3';
import { computed, ref, watch, nextTick } from 'vue';
import AppLayout from '@/Layouts/AppLayout.vue';
import Sheet from '@/Components/Sheet.vue';
import ColorSizePicker from '@/Components/ColorSizePicker.vue';
import CameraScanner from '@/Components/CameraScanner.vue';
import { useI18n } from '@/composables/useI18n';
import { useHardwareScanner } from '@/composables/useHardwareScanner';
import { useToast } from '@/composables/useToast';

const props = defineProps({ channels: Array, products: Array, couriers: { type: Array, default: () => [] } });
const { t } = useI18n();
const page = usePage();
const { toast } = useToast();

const money = (n) => '৳' + Math.round(n).toLocaleString('en-IN');

const form = useForm({
    channel_id: props.channels[0]?.id || '',
    customer_phone: '', customer_name: '',
    items: [{ product_id: '', product_variant_id: '', qty: 1, imei: '' }],
    delivery_charge: '', cod_amount: '', paid_amount: '', paid_method: 'cash',
    campaign_tag: '', external_reference: '',
    courier_id: '', parcel_weight: '', is_outside_dhaka: false,
});

const selectedCourier = computed(() => props.couriers.find((c) => c.id === form.courier_id));

function productOf(line) {
    return props.products.find((p) => p.id === line.product_id);
}
function variantsOf(line) {
    return productOf(line)?.variants || [];
}
function isWeighed(line) {
    return !!productOf(line)?.sold_by_weight;
}
function unitPrice(line) {
    const p = productOf(line);
    if (!p) return 0;
    if (line.product_variant_id) {
        const v = variantsOf(line).find((x) => x.id === line.product_variant_id);
        return v ? Number(v.price || p.price) : 0;
    }
    return Number(p.discount_price ?? p.price);
}
// repeat-customer / fake-order risk — a warning, never a block (see
// docs/FIX_PLAN.md's Phase 32 design note)
const risk = ref(null);
let riskTimer = null;
watch(() => form.customer_phone, (val) => {
    clearTimeout(riskTimer);
    risk.value = null;
    const phone = (val || '').trim();
    if (phone.length < 6) return;
    riskTimer = setTimeout(async () => {
        try {
            const res = await fetch(route('app.onlineOrders.customerRisk') + '?phone=' + encodeURIComponent(phone), { headers: { Accept: 'application/json' } });
            risk.value = await res.json();
        } catch (e) { /* not critical */ }
    }, 400);
});

function addLine() {
    form.items.push({ product_id: '', product_variant_id: '', qty: 1, imei: '' });
}
function removeLine(i) {
    form.items.splice(i, 1);
}
function onProductChange(line) {
    line.product_variant_id = '';
}

/**
 * Khaled: "online order khetre barcode scan kore easily sales" — scan-to-
 * add a line, same lookup order as Pos/Index.vue's handleBarcode() (a
 * variant's own barcode first, since it's more specific, then the bare
 * product barcode). Bumps an already-present line's qty instead of adding
 * a duplicate one, same as a repeat scan in POS.
 */
const scanInput = ref('');
function handleBarcode(code) {
    const trimmed = code.trim();
    if (!trimmed) return;

    // docs/ARTICLE_SERIES_PLAN.md §10 — a short printed-symbol code
    // (ZV{variantId}/ZC{colorBarcodeId}), decoupled from the full
    // descriptive text still printed on the label below it.
    const zv = trimmed.match(/^ZV(\d+)$/);
    if (zv) {
        const vid = Number(zv[1]);
        for (const p of props.products) {
            const v = p.variants?.find((variant) => variant.id === vid);
            if (v) {
                addScannedLine(p.id, v.id, `${p.name} (${[v.size, v.color].filter(Boolean).join(', ')})`);
                return;
            }
        }
    }
    const zc = trimmed.match(/^ZC(\d+)$/);
    if (zc) {
        const cid = Number(zc[1]);
        for (const p of props.products) {
            const cb = p.color_barcodes?.find((c) => c.id === cid);
            if (cb) {
                openScanColorPicker(p, cb.color);
                return;
            }
        }
    }

    for (const p of props.products) {
        const v = p.variants?.find((variant) => variant.barcode === trimmed);
        if (v) {
            addScannedLine(p.id, v.id, `${p.name} (${[v.size, v.color].filter(Boolean).join(', ')})`);
            return;
        }
    }

    // tier 2 — docs/ARTICLE_SERIES_PLAN.md §9's color-level barcode
    for (const p of props.products) {
        const cb = p.color_barcodes?.find((c) => c.barcode === trimmed);
        if (cb) {
            openScanColorPicker(p, cb.color);
            return;
        }
    }

    const product = props.products.find((p) => p.barcode === trimmed);
    if (product) {
        // the ARTICLE's own barcode (e.g. a shelf tag) is ambiguous — which
        // size/color? Open the picker instead of leaving a half-filled line.
        if (product.variants?.length) {
            closeCamera();
            scanVariantProduct.value = product;
            scanVariantOnlyColor.value = null;
            scanVariantSheetOpen.value = true;
            return;
        }
        addScannedLine(product.id, '', product.name);
        return;
    }

    toast('❌ ' + t('pos.notFound'));
}
const scanVariantSheetOpen = ref(false);
const scanVariantProduct = ref(null);
const scanVariantOnlyColor = ref(null);
function onScanVariantPick(variantId, qty) {
    const v = scanVariantProduct.value.variants.find((x) => x.id === variantId);
    addScannedLine(scanVariantProduct.value.id, variantId, `${scanVariantProduct.value.name} (${[v.size, v.color].filter(Boolean).join(', ')})`, qty);
    scanVariantSheetOpen.value = false;
}

/** Exactly one in-stock size for that color adds straight to the line, same spirit as an exact scan; more than one opens the picker filtered to just that color. */
function openScanColorPicker(product, color) {
    const sizesInStock = (product.variants || []).filter((v) => v.color === color && Number(v.stock) > 0);
    if (sizesInStock.length === 1) {
        const v = sizesInStock[0];
        addScannedLine(product.id, v.id, `${product.name} (${[v.size, v.color].filter(Boolean).join(', ')})`);
        return;
    }
    closeCamera();
    scanVariantProduct.value = product;
    scanVariantOnlyColor.value = color;
    scanVariantSheetOpen.value = true;
}
function addScannedLine(productId, variantId, label, qty = 1) {
    const existing = form.items.find((l) => l.product_id === productId && (l.product_variant_id || '') === variantId);
    if (existing) {
        existing.qty = (Number(existing.qty) || 0) + qty;
    } else {
        const emptyLine = form.items.find((l) => !l.product_id);
        if (emptyLine) {
            emptyLine.product_id = productId;
            emptyLine.product_variant_id = variantId;
            emptyLine.qty = qty;
        } else {
            form.items.push({ product_id: productId, product_variant_id: variantId, qty, imei: '' });
        }
    }
    toast('✅ ' + label);
}
function onScanInputEnter() {
    handleBarcode(scanInput.value);
    scanInput.value = '';
}

// visible 📷 button, same recognizable affordance as POS's own scanner —
// Khaled: "r oneline order e abar gelam kono option dkehlam na san korar"
// — the hardware-scanner/manual-type field alone wasn't obviously a "scan"
// option to look for; this gives Online Order the same camera overlay POS has.
const cameraOpen = ref(false);
const cameraRef = ref(null);
async function openCamera() {
    cameraOpen.value = true;
    await nextTick();
    cameraRef.value?.open();
}
function closeCamera() {
    cameraOpen.value = false;
}
function onCameraScan(decodedText, onFeedback) {
    handleBarcode(decodedText);
    onFeedback('✅');
}

const hardwareScannerEnabled = computed(() => page.props.shop?.hardware_scanner_enabled ?? true);
useHardwareScanner(handleBarcode, hardwareScannerEnabled);

const subtotal = computed(() => form.items.reduce((s, l) => s + unitPrice(l) * (Number(l.qty) || 0), 0));
const total = computed(() => subtotal.value + (Number(form.delivery_charge) || 0));
const dueRemainder = computed(() => Math.max(0, total.value - (Number(form.cod_amount) || 0) - (Number(form.paid_amount) || 0)));

// cod_amount defaults to "whatever's left after any advance payment" so
// Khaled never has to add up subtotal+delivery_charge himself for the
// overwhelming common case (full COD, nothing paid in advance) — stops
// auto-filling the moment he types into the field himself, so a deliberate
// override (e.g. the courier will only collect part of it) always sticks.
const codTouched = ref(false);
watch([total, () => form.paid_amount], () => {
    if (!codTouched.value) form.cod_amount = Math.max(0, total.value - (Number(form.paid_amount) || 0));
});

function save() {
    form.transform((data) => ({
        ...data,
        items: data.items.filter((l) => l.product_id).map((l) => ({
            product_id: l.product_id, product_variant_id: l.product_variant_id || null,
            qty: l.qty, imei: l.imei || null,
        })),
    })).post(route('app.onlineOrders.store'));
}
</script>

<template>
    <Head :title="t('onlineOrder.newTitle')" />
    <AppLayout active="onlineOrders">
        <div class="pgttl">{{ t('onlineOrder.newTitle') }}</div>
        <div class="pgsub">{{ t('onlineOrder.newSubtitle') }}</div>

        <Link :href="route('app.onlineOrders.index')" class="btn ghost sm" style="margin-bottom:16px;display:inline-block;text-decoration:none">← {{ t('common.back') }}</Link>

        <div class="field">
            <label>{{ t('channel.title') }}</label>
            <select v-model="form.channel_id">
                <option v-for="c in channels" :key="c.id" :value="c.id">{{ c.name }}</option>
            </select>
            <div v-if="form.errors.channel_id" style="color:var(--rose);font-size:12px;margin-top:6px">{{ form.errors.channel_id }}</div>
        </div>

        <div class="f2">
            <div class="field">
                <label>{{ t('due.mobile') }}</label>
                <input v-model="form.customer_phone" inputmode="tel" placeholder="01XXXXXXXXX">
                <div v-if="form.errors.customer_phone" style="color:var(--rose);font-size:12px;margin-top:6px">{{ form.errors.customer_phone }}</div>
                <div v-if="risk?.found && risk.cancelled_or_returned > 0" style="color:var(--rose);font-size:12px;margin-top:6px">
                    ⚠️ {{ t('onlineOrder.riskWarning', { cancelled: risk.cancelled_or_returned, delivered: risk.delivered }) }}
                </div>
            </div>
            <div class="field">
                <label>{{ t('due.name') }} <span style="color:var(--dim);font-weight:400">{{ t('stock.optional') }}</span></label>
                <input v-model="form.customer_name">
            </div>
        </div>

        <div class="field">
            <label>{{ t('pos.scanBarcode') }}</label>
            <div style="display:flex;gap:8px">
                <input
                    v-model="scanInput" :placeholder="t('pos.scanBarcodeHint')" inputmode="none" autocomplete="off"
                    style="flex:1" @keyup.enter="onScanInputEnter"
                >
                <button type="button" class="btn ghost" style="width:auto;padding:0 16px" :title="t('pos.scanTitle')" @click="openCamera">📷</button>
            </div>
        </div>

        <CameraScanner v-if="cameraOpen" ref="cameraRef" @scan="onCameraScan" @close="closeCamera" @error="toast('❌ ' + t('pos.cameraNotFound'))" />

        <Sheet v-model="scanVariantSheetOpen" :title="scanVariantProduct?.name" :subtitle="t('pos.pickVariantHint')">
            <ColorSizePicker v-if="scanVariantProduct" :product="scanVariantProduct" :only-color="scanVariantOnlyColor" @pick="onScanVariantPick" />
        </Sheet>

        <div class="field"><label>{{ t('quotation.items') }}</label></div>
        <div v-for="(line, i) in form.items" :key="i" class="card" style="margin-bottom:10px">
            <select v-model="line.product_id" @change="onProductChange(line)" style="margin-bottom:8px">
                <option value="">{{ t('damage.selectPlaceholder') }}</option>
                <option v-for="p in products" :key="p.id" :value="p.id">{{ p.emoji }} {{ p.name }} — {{ money(p.discount_price ?? p.price) }}<template v-if="!p.variants?.length"> ({{ p.stock }})</template></option>
            </select>
            <select v-if="variantsOf(line).length" v-model="line.product_variant_id" style="margin-bottom:8px">
                <option value="">{{ t('stock.selectVariant') }}</option>
                <option v-for="v in variantsOf(line)" :key="v.id" :value="v.id">{{ v.size }} {{ v.color }} ({{ v.stock }})</option>
            </select>
            <div class="f2">
                <div class="field" style="margin:0">
                    <label>{{ t('return.qty') }}</label>
                    <input v-model.number="line.qty" type="number" :min="isWeighed(line) ? 0.001 : 1" :step="isWeighed(line) ? 0.001 : 1">
                </div>
                <button type="button" class="btn sm rose" style="align-self:flex-end" :disabled="form.items.length <= 1" @click="removeLine(i)">{{ t('common.cancel') }}</button>
            </div>
            <div v-if="form.errors[`items.${i}.product_id`]" style="color:var(--rose);font-size:12px;margin-top:6px">{{ form.errors[`items.${i}.product_id`] }}</div>
        </div>
        <button type="button" class="btn ghost sm" style="width:100%;margin-bottom:14px" @click="addLine">{{ t('quotation.addLine') }}</button>

        <div class="f2">
            <div class="field">
                <label>{{ t('onlineOrder.deliveryCharge') }}</label>
                <input v-model.number="form.delivery_charge" type="number" min="0" placeholder="0">
            </div>
            <div class="field">
                <label>{{ t('onlineOrder.codAmount') }} <span style="color:var(--dim);font-weight:400">{{ t('onlineOrder.codHint') }}</span></label>
                <input v-model.number="form.cod_amount" type="number" min="0" placeholder="0" @input="codTouched = true">
            </div>
        </div>

        <div v-if="couriers.length" class="field">
            <label>{{ t('courier.pickCourier') }} <span style="color:var(--dim);font-weight:400">{{ t('stock.optional') }}</span></label>
            <select v-model="form.courier_id">
                <option value="">{{ t('damage.selectPlaceholder') }}</option>
                <option v-for="c in couriers" :key="c.id" :value="c.id">{{ c.name }}</option>
            </select>
        </div>
        <div v-if="selectedCourier?.charge_type === 'weight'" class="field">
            <label>{{ t('courier.parcelWeight') }}</label>
            <input v-model.number="form.parcel_weight" type="number" min="0" step="0.01" placeholder="0.5">
        </div>
        <div v-if="selectedCourier?.charge_type === 'zone'" class="field">
            <label style="display:flex;align-items:center;gap:8px;cursor:pointer">
                <input v-model="form.is_outside_dhaka" type="checkbox">
                {{ t('courier.outsideDhaka') }}
            </label>
        </div>

        <div class="f2">
            <div class="field">
                <label>{{ t('onlineOrder.paidAmount') }} <span style="color:var(--dim);font-weight:400">{{ t('stock.optional') }}</span></label>
                <input v-model.number="form.paid_amount" type="number" min="0" placeholder="0">
                <div v-if="form.errors.paid_method" style="color:var(--rose);font-size:12px;margin-top:6px">{{ form.errors.paid_method }}</div>
            </div>
            <div v-if="Number(form.paid_amount) > 0" class="field">
                <label>{{ t('onlineOrder.paidMethod') }}</label>
                <select v-model="form.paid_method">
                    <option value="cash">{{ t('pay.cash') }}</option>
                    <option value="bkash">{{ t('pay.bkash') }}</option>
                    <option value="nagad">{{ t('pay.nagad') }}</option>
                </select>
            </div>
        </div>

        <div class="field">
            <label>{{ t('onlineOrder.campaignTag') }} <span style="color:var(--dim);font-weight:400">{{ t('stock.optional') }}</span></label>
            <input v-model="form.campaign_tag" placeholder="e.g. eid-2026-boost">
        </div>

        <div class="card" style="margin-bottom:14px">
            <div style="display:flex;justify-content:space-between;padding:3px 0;color:var(--mut)"><span>{{ t('pos.subtotal') }}</span><b>{{ money(subtotal) }}</b></div>
            <div style="display:flex;justify-content:space-between;padding:3px 0;color:var(--mut)"><span>{{ t('onlineOrder.deliveryCharge') }}</span><b>{{ money(Number(form.delivery_charge) || 0) }}</b></div>
            <div class="hr" style="margin:8px 0"></div>
            <div style="display:flex;justify-content:space-between;font-size:19px;font-weight:800"><span>{{ t('pos.grandTotal') }}</span><b style="color:var(--gold)">{{ money(total) }}</b></div>
            <div v-if="dueRemainder > 0" style="display:flex;justify-content:space-between;margin-top:6px;color:var(--rose);font-size:13px">
                <span>{{ t('onlineOrder.dueRemainder') }}</span><b>{{ money(dueRemainder) }}</b>
            </div>
        </div>

        <div v-if="form.errors.items" class="card" style="margin-bottom:14px;background:var(--roseSoft);color:var(--rose)">{{ form.errors.items }}</div>

        <button class="btn" :disabled="form.processing" @click="save">{{ form.processing ? '...' : t('onlineOrder.createSubmit') }}</button>
    </AppLayout>
</template>
