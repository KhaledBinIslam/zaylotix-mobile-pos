<script setup>
import { Head, Link, useForm } from '@inertiajs/vue3';
import { ref } from 'vue';
import AppLayout from '@/Layouts/AppLayout.vue';
import Sheet from '@/Components/Sheet.vue';
import Pagination from '@/Components/Pagination.vue';
import { useI18n } from '@/composables/useI18n';

const props = defineProps({ tickets: Object, status: String, products: Array });
const { t } = useI18n();

const money = (n) => '৳' + Math.round(n).toLocaleString('en-IN');
const fmt = (dt) => new Date(dt).toLocaleString('en-GB', { day: '2-digit', month: 'short', hour: '2-digit', minute: '2-digit' });
const ticketTotal = (ticket) => (ticket.items || []).reduce((sum, i) => sum + Number(i.price) * Number(i.qty), 0);

const statusLabel = (s) => ({
    received: t('service.statusReceived'), in_progress: t('service.statusInProgress'), waiting_parts: t('service.statusWaitingParts'),
    ready: t('service.statusReady'), delivered: t('service.statusDelivered'), cancelled: t('service.statusCancelled'),
}[s] || s);

const statusColor = (s) => ({
    received: 'var(--mut)', in_progress: 'var(--amber)', waiting_parts: 'var(--amber)',
    ready: 'var(--green)', delivered: 'var(--green)', cancelled: 'var(--rose)',
}[s] || 'var(--mut)');

const createSheet = ref(false);
const form = useForm({
    device_label: '', product_id: '', imei: '', problem_description: '', technician_name: '',
    is_warranty_claim: false, promised_at: '', customer_phone: '', customer_name: '',
});

function openCreate() {
    form.reset();
    createSheet.value = true;
}

function pickProduct() {
    const p = props.products.find((p) => p.id === Number(form.product_id));
    if (p && !form.device_label) form.device_label = p.name;
}

function save() {
    form.post(route('app.serviceTickets.store'));
}
</script>

<template>
    <Head :title="t('service.title')" />
    <AppLayout active="more">
        <div class="pgttl">🛠️ {{ t('service.title') }}</div>
        <div class="pgsub">{{ t('service.subtitle') }}</div>

        <button class="btn" style="margin-bottom:14px" @click="openCreate">+ {{ t('service.newTicket') }}</button>

        <div class="seg" style="margin-bottom:14px">
            <Link :href="route('app.serviceTickets.index')" :class="{ on: !status }">{{ t('service.statusAll') }}</Link>
            <Link :href="route('app.serviceTickets.index', { status: 'received' })" :class="{ on: status === 'received' }">{{ t('service.statusReceived') }}</Link>
            <Link :href="route('app.serviceTickets.index', { status: 'in_progress' })" :class="{ on: status === 'in_progress' }">{{ t('service.statusInProgress') }}</Link>
            <Link :href="route('app.serviceTickets.index', { status: 'ready' })" :class="{ on: status === 'ready' }">{{ t('service.statusReady') }}</Link>
        </div>

        <Link v-for="ticket in tickets.data" :key="ticket.id" :href="route('app.serviceTickets.show', ticket.id)" class="row">
            <div class="ava">🛠️</div>
            <div class="mid">
                <b>#{{ ticket.id }} {{ ticket.device_label }}</b>
                <span>{{ ticket.customer?.name || t('service.noCustomer') }} • {{ fmt(ticket.received_at) }}</span>
            </div>
            <div class="end">
                <b :style="{ color: statusColor(ticket.status) }">{{ statusLabel(ticket.status) }}</b>
                <div style="font-size:12px;color:var(--mut)">{{ money(ticketTotal(ticket)) }}</div>
            </div>
        </Link>
        <div v-if="!tickets.data.length" class="empty"><div class="big">🛠️</div>{{ t('service.noTickets') }}</div>
        <Pagination :links="tickets.links" />

        <Sheet v-model="createSheet" :title="t('service.newTicket')">
            <div class="field">
                <label>{{ t('service.deviceLabel') }}</label>
                <input v-model="form.device_label" :placeholder="t('service.deviceLabelPlaceholder')">
                <div v-if="form.errors.device_label" style="color:var(--rose);font-size:12px;margin-top:6px">{{ form.errors.device_label }}</div>
            </div>
            <div class="field">
                <label>{{ t('service.relatedProduct') }} <span style="color:var(--dim);font-weight:400">{{ t('stock.optional') }}</span></label>
                <select v-model="form.product_id" @change="pickProduct">
                    <option :value="''">{{ t('stock.selectPlaceholder') }}</option>
                    <option v-for="p in products" :key="p.id" :value="p.id">{{ p.emoji }} {{ p.name }}</option>
                </select>
            </div>
            <div class="field">
                <label>{{ t('service.imei') }} <span style="color:var(--dim);font-weight:400">{{ t('stock.optional') }}</span></label>
                <input v-model="form.imei" :placeholder="t('service.imeiPlaceholder')">
            </div>
            <div class="field">
                <label>{{ t('service.problem') }}</label>
                <textarea v-model="form.problem_description" rows="3"></textarea>
            </div>
            <div class="f2">
                <div class="field"><label>{{ t('service.customerPhone') }}</label><input v-model="form.customer_phone" type="tel"></div>
                <div class="field"><label>{{ t('service.customerName') }}</label><input v-model="form.customer_name"></div>
            </div>
            <div class="f2">
                <div class="field"><label>{{ t('service.technician') }}</label><input v-model="form.technician_name"></div>
                <div class="field"><label>{{ t('service.promisedDate') }}</label><input v-model="form.promised_at" type="date"></div>
            </div>
            <label style="display:flex;align-items:center;gap:8px;cursor:pointer;margin-bottom:14px">
                <input v-model="form.is_warranty_claim" type="checkbox">
                {{ t('service.isWarrantyClaim') }}
            </label>
            <button class="btn" :disabled="form.processing || !form.device_label" @click="save">{{ form.processing ? '...' : t('service.createButton') }}</button>
            <button class="btn ghost" style="margin-top:10px" @click="createSheet = false">{{ t('common.cancel') }}</button>
        </Sheet>
    </AppLayout>
</template>
