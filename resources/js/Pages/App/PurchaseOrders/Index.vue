<script setup>
import { Head, useForm, Link } from '@inertiajs/vue3';
import { ref, computed } from 'vue';
import AppLayout from '@/Layouts/AppLayout.vue';
import Sheet from '@/Components/Sheet.vue';
import { useI18n } from '@/composables/useI18n';

const props = defineProps({ purchaseOrders: Array, suppliers: Array, products: Array });
const { t } = useI18n();

const money = (n) => '৳' + Math.round(n).toLocaleString('en-IN');

const statusLabel = {
    ordered: 'purchaseOrder.statusOrdered', partially_received: 'purchaseOrder.statusPartiallyReceived',
    received: 'purchaseOrder.statusReceived', cancelled: 'purchaseOrder.statusCancelled',
};
const statusPill = { ordered: 'gold', partially_received: 'gold', received: 'mint', cancelled: 'mut' };

const sheet = ref(false);
const form = useForm({ supplier_id: '', expected_date: '', notes: '', items: [] });

function openNew() {
    form.reset();
    form.clearErrors();
    form.items = [{ product_id: '', product_variant_id: '', qty: '', cost: '' }];
    sheet.value = true;
}
function addLine() {
    form.items.push({ product_id: '', product_variant_id: '', qty: '', cost: '' });
}
function removeLine(i) {
    form.items.splice(i, 1);
}
function productOf(line) {
    return props.products.find((p) => p.id === line.product_id);
}
function onProductPick(line) {
    line.product_variant_id = '';
    const p = productOf(line);
    if (p && !p.variants.length) line.cost = p.cost;
}
function onVariantPick(line) {
    const v = productOf(line)?.variants.find((x) => x.id === line.product_variant_id);
    if (v) line.cost = v.cost;
}
const lineTotal = (l) => (Number(l.qty) || 0) * (Number(l.cost) || 0);
const subtotal = computed(() => form.items.reduce((s, l) => s + lineTotal(l), 0));

function save() {
    form.post(route('app.purchaseOrders.store'));
}
</script>

<template>
    <Head :title="t('purchaseOrder.title')" />
    <AppLayout active="more">
        <div class="pgttl">📑 {{ t('purchaseOrder.title') }}</div>
        <div class="pgsub">{{ t('purchaseOrder.subtitle') }}</div>

        <button class="btn ghost" style="margin-bottom:16px" @click="openNew">{{ t('purchaseOrder.newButton') }}</button>

        <div v-for="po in purchaseOrders" :key="po.id" class="row" style="cursor:default">
            <Link :href="route('app.purchaseOrders.show', po.id)" class="ava" style="text-decoration:none">📑</Link>
            <Link :href="route('app.purchaseOrders.show', po.id)" class="mid" style="text-decoration:none;color:inherit">
                <b>{{ po.po_number }}</b>
                <span>{{ po.supplier?.name }} • {{ po.date }} • {{ po.items_count }} {{ t('purchaseOrder.lines') }}</span>
            </Link>
            <div class="end">
                <b>{{ money(po.subtotal) }}</b>
                <span class="pill" :class="statusPill[po.status]" style="margin-top:3px">{{ t(statusLabel[po.status]) }}</span>
            </div>
        </div>
        <div v-if="!purchaseOrders.length" class="empty"><div class="big">📑</div>{{ t('purchaseOrder.noOrders') }}</div>

        <Sheet v-model="sheet" :title="t('purchaseOrder.newButton')">
            <div class="field">
                <label>{{ t('purchaseOrder.supplier') }}</label>
                <select v-model.number="form.supplier_id">
                    <option value="">{{ t('damage.selectPlaceholder') }}</option>
                    <option v-for="s in suppliers" :key="s.id" :value="s.id">{{ s.name }}</option>
                </select>
                <div v-if="form.errors.supplier_id" style="color:var(--rose);font-size:12px;margin-top:6px">{{ form.errors.supplier_id }}</div>
            </div>

            <div class="field"><label>{{ t('purchaseOrder.lines') }}</label></div>
            <div v-for="(line, i) in form.items" :key="i" class="card" style="margin-bottom:10px">
                <select v-model.number="line.product_id" @change="onProductPick(line)" style="margin-bottom:8px">
                    <option value="">{{ t('damage.selectPlaceholder') }}</option>
                    <option v-for="p in products" :key="p.id" :value="p.id">{{ p.emoji }} {{ p.name }}</option>
                </select>
                <select v-if="productOf(line)?.variants.length" v-model.number="line.product_variant_id" @change="onVariantPick(line)" style="margin-bottom:8px">
                    <option value="">{{ t('purchaseOrder.pickVariant') }}</option>
                    <option v-for="v in productOf(line).variants" :key="v.id" :value="v.id">{{ v.label }}</option>
                </select>
                <div class="f2">
                    <div class="field" style="margin:0"><label>{{ t('return.qty') }}</label><input v-model.number="line.qty" type="number" min="0.001" step="0.001"></div>
                    <div class="field" style="margin:0"><label>{{ t('purchaseOrder.unitCost') }}</label><input v-model.number="line.cost" type="number" min="0"></div>
                </div>
                <div style="display:flex;justify-content:space-between;margin-top:8px">
                    <span style="font-size:12px;color:var(--mut)">{{ t('purchaseOrder.lineTotal') }}: {{ money(lineTotal(line)) }}</span>
                    <button type="button" class="btn sm rose" style="width:auto;padding:4px 14px" @click="removeLine(i)" :disabled="form.items.length <= 1">{{ t('common.cancel') }}</button>
                </div>
                <div v-if="form.errors[`items.${i}.product_id`]" style="color:var(--rose);font-size:12px;margin-top:6px">{{ form.errors[`items.${i}.product_id`] }}</div>
                <div v-if="form.errors[`items.${i}.qty`]" style="color:var(--rose);font-size:12px;margin-top:6px">{{ form.errors[`items.${i}.qty`] }}</div>
            </div>
            <button type="button" class="btn ghost sm" style="width:100%;margin-bottom:14px" @click="addLine">{{ t('purchaseOrder.addLine') }}</button>

            <div class="field"><label>{{ t('purchaseOrder.expectedDate') }} <span style="color:var(--dim);font-weight:400">{{ t('stock.optional') }}</span></label><input v-model="form.expected_date" type="date"></div>
            <div class="field"><label>{{ t('quotation.notes') }} <span style="color:var(--dim);font-weight:400">{{ t('stock.optional') }}</span></label><textarea v-model="form.notes" rows="2"></textarea></div>

            <div class="card" style="margin-bottom:14px;display:flex;justify-content:space-between;font-size:19px;font-weight:800">
                <span>{{ t('pos.subtotal') }}</span><b style="color:var(--gold)">{{ money(subtotal) }}</b>
            </div>

            <button class="btn" :disabled="form.processing" @click="save">{{ form.processing ? '...' : t('purchaseOrder.save') }}</button>
            <button class="btn ghost" style="margin-top:10px" @click="sheet = false">{{ t('common.cancel') }}</button>
        </Sheet>
    </AppLayout>
</template>
