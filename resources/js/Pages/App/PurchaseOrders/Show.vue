<script setup>
import { Head, useForm, router, Link } from '@inertiajs/vue3';
import { ref, computed } from 'vue';
import AppLayout from '@/Layouts/AppLayout.vue';
import Sheet from '@/Components/Sheet.vue';
import { useI18n } from '@/composables/useI18n';

const props = defineProps({ po: Object });
const { t } = useI18n();

const money = (n) => '৳' + Math.round(n).toLocaleString('en-IN');
const qty = (n) => {
    const num = Number(n || 0);
    return Number.isInteger(num) ? String(num) : String(parseFloat(num.toFixed(3)));
};

const statusLabel = {
    ordered: 'purchaseOrder.statusOrdered', partially_received: 'purchaseOrder.statusPartiallyReceived',
    received: 'purchaseOrder.statusReceived', cancelled: 'purchaseOrder.statusCancelled',
};
const statusPill = { ordered: 'gold', partially_received: 'gold', received: 'mint', cancelled: 'mut' };

function remaining(item) {
    return Math.max(0, Number(item.qty_ordered) - Number(item.qty_received));
}
const openForReceiving = computed(() => props.po.items.filter((i) => remaining(i) > 0));

// --- receive sheet ---
const receiveSheet = ref(false);
const receiveQty = ref({}); // item id -> qty string
const form = useForm({ method: 'credit', items: [] });
function openReceive() {
    receiveQty.value = {};
    openForReceiving.value.forEach((i) => (receiveQty.value[i.id] = ''));
    form.reset();
    form.clearErrors();
    receiveSheet.value = true;
}
const receiveTotal = computed(() => openForReceiving.value.reduce((s, i) => s + (Number(receiveQty.value[i.id]) || 0) * Number(i.cost), 0));
function submitReceive() {
    form.items = Object.entries(receiveQty.value)
        .filter(([, v]) => Number(v) > 0)
        .map(([id, v]) => ({ id: Number(id), qty: Number(v) }));
    if (!form.items.length) return;
    form.post(route('app.purchaseOrders.receive', props.po.id), {
        onSuccess: () => (receiveSheet.value = false),
    });
}

function cancelOrder() {
    if (!confirm(t('purchaseOrder.cancelConfirm'))) return;
    router.post(route('app.purchaseOrders.cancel', props.po.id));
}
</script>

<template>
    <Head :title="po.po_number" />
    <AppLayout active="more">
        <div class="pgttl">📑 {{ po.po_number }}</div>
        <div class="pgsub">{{ po.supplier?.name }}<span v-if="po.supplier?.phone"> • {{ po.supplier.phone }}</span></div>

        <div class="card" style="margin-bottom:14px">
            <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:8px">
                <span class="pill" :class="statusPill[po.status]">{{ t(statusLabel[po.status]) }}</span>
                <b style="font-size:19px;color:var(--gold)">{{ money(po.subtotal) }}</b>
            </div>
            <div style="font-size:12px;color:var(--mut)">{{ t('purchaseOrder.orderedOn') }} {{ po.date }}<span v-if="po.expected_date"> • {{ t('purchaseOrder.expectedOn') }} {{ po.expected_date }}</span></div>
            <div v-if="po.notes" style="font-size:12.5px;color:var(--mut);margin-top:6px">{{ po.notes }}</div>
        </div>

        <div class="sechead"><h2>{{ t('purchaseOrder.lines') }}</h2></div>
        <div v-for="item in po.items" :key="item.id" class="card" style="margin-bottom:10px">
            <b>{{ item.product_name }}{{ item.variant_label ? ' (' + item.variant_label + ')' : '' }}</b>
            <div style="display:flex;justify-content:space-between;margin-top:6px;font-size:12.5px;color:var(--mut)">
                <span>{{ t('purchaseOrder.ordered') }}: {{ qty(item.qty_ordered) }}</span>
                <span>{{ t('purchaseOrder.received') }}: {{ qty(item.qty_received) }}</span>
                <span :style="remaining(item) > 0 ? 'color:var(--gold2);font-weight:700' : ''">{{ t('purchaseOrder.remaining') }}: {{ qty(remaining(item)) }}</span>
            </div>
        </div>

        <div class="btnrow" style="margin-top:16px">
            <button v-if="openForReceiving.length" class="btn sm" style="flex:1" @click="openReceive">📦 {{ t('purchaseOrder.receiveButton') }}</button>
            <button v-if="po.status === 'ordered'" class="btn ghost sm" style="flex:1" @click="cancelOrder">✕ {{ t('purchaseOrder.cancelButton') }}</button>
        </div>

        <Sheet v-model="receiveSheet" :title="t('purchaseOrder.receiveButton')">
            <div v-for="item in openForReceiving" :key="item.id" class="field">
                <label>{{ item.product_name }}{{ item.variant_label ? ' (' + item.variant_label + ')' : '' }} <span style="color:var(--dim);font-weight:400">({{ t('purchaseOrder.remaining') }} {{ qty(remaining(item)) }})</span></label>
                <input v-model.number="receiveQty[item.id]" type="number" min="0" :max="remaining(item)" step="0.001" placeholder="0">
            </div>

            <div class="field">
                <label>{{ t('pos.payment') }}</label>
                <div class="seg">
                    <button :class="{ on: form.method === 'cash' }" @click="form.method = 'cash'">{{ t('pay.cash') }}</button>
                    <button :class="{ on: form.method === 'bank' }" @click="form.method = 'bank'">{{ t('purchaseOrder.bank') }}</button>
                    <button :class="{ on: form.method === 'credit' }" @click="form.method = 'credit'">{{ t('pay.credit') }}</button>
                </div>
            </div>

            <div class="card" style="margin-bottom:14px;display:flex;justify-content:space-between;align-items:center">
                <b>{{ t('purchaseOrder.receivingNow') }}</b><b style="font-size:19px;color:var(--gold)">{{ money(receiveTotal) }}</b>
            </div>
            <div v-if="form.errors.items" style="color:var(--rose);font-size:12px;margin-bottom:8px">{{ form.errors.items }}</div>

            <button class="btn" :disabled="!receiveTotal || form.processing" @click="submitReceive">{{ form.processing ? '...' : t('purchaseOrder.receiveButton') }}</button>
            <button class="btn ghost" style="margin-top:10px" @click="receiveSheet = false">{{ t('common.cancel') }}</button>
        </Sheet>
    </AppLayout>
</template>
