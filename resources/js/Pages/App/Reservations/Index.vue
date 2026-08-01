<script setup>
import { Head, useForm, router } from '@inertiajs/vue3';
import { ref } from 'vue';
import AppLayout from '@/Layouts/AppLayout.vue';
import Sheet from '@/Components/Sheet.vue';
import { useI18n } from '@/composables/useI18n';

const props = defineProps({ reservations: Array, freeTables: Array, date: String });
const { t } = useI18n();
const money = (n) => '৳' + Math.round(n).toLocaleString('en-IN');

const dateFilter = ref(props.date || '');
function applyDateFilter() {
    router.get(route('app.reservations.index'), dateFilter.value ? { date: dateFilter.value } : {}, { preserveState: true });
}

function nowLocalForInput() {
    const d = new Date();
    d.setMinutes(d.getMinutes() - d.getTimezoneOffset());
    return d.toISOString().slice(0, 16);
}

const sheet = ref(false);
const editing = ref(null);
const form = useForm({ name: '', phone: '', reservation_at: nowLocalForInput(), restaurant_table_id: '', guest_count: 2, note: '', advance: '' });

function openNew() {
    editing.value = null;
    form.reset();
    form.reservation_at = nowLocalForInput();
    form.guest_count = 2;
    sheet.value = true;
}
function openEdit(r) {
    editing.value = r;
    form.name = r.name;
    form.phone = r.phone || '';
    form.reservation_at = r.reservation_at.slice(0, 16);
    form.restaurant_table_id = r.restaurant_table_id || '';
    form.guest_count = r.guest_count;
    form.note = r.note || '';
    form.advance = r.advance || '';
    sheet.value = true;
}
function save() {
    if (editing.value) {
        form.put(route('app.reservations.update', editing.value.id), { onSuccess: () => { sheet.value = false; } });
    } else {
        form.post(route('app.reservations.store'), { onSuccess: () => { sheet.value = false; form.reset(); } });
    }
}

function seat(r) {
    router.post(route('app.reservations.seat', r.id));
}
function cancelReservation(r) {
    if (!confirm(t('reservation.cancelConfirm'))) return;
    router.post(route('app.reservations.cancel', r.id), {}, { preserveScroll: true });
}
function markNoShow(r) {
    if (!confirm(t('reservation.noShowConfirm'))) return;
    router.post(route('app.reservations.noShow', r.id), {}, { preserveScroll: true });
}

const STATUS_LABEL = { reserved: 'reservation.statusReserved', seated: 'reservation.statusSeated', cancelled: 'reservation.statusCancelled', no_show: 'reservation.statusNoShow' };
const STATUS_CLASS = { reserved: 'gold', seated: 'mint', cancelled: 'rose', no_show: 'rose' };
</script>

<template>
    <Head :title="t('reservation.title')" />
    <AppLayout active="reservations">
        <div class="pgttl">{{ t('reservation.title') }}</div>
        <div class="pgsub">{{ t('reservation.subtitle') }}</div>

        <div style="display:flex;gap:8px;margin-bottom:14px">
            <input v-model="dateFilter" type="date" style="flex:1;margin:0" @change="applyDateFilter">
            <button v-if="dateFilter" class="btn sm ghost" style="width:auto;padding:0 14px" @click="dateFilter = ''; applyDateFilter()">{{ t('reservation.clearDate') }}</button>
        </div>

        <button class="btn" style="margin-bottom:16px" @click="openNew">{{ t('reservation.addReservation') }}</button>

        <div v-for="r in reservations" :key="r.id" class="card" style="margin-bottom:12px">
            <div style="display:flex;justify-content:space-between;align-items:start">
                <div style="min-width:0">
                    <b style="font-size:15px">{{ r.name }}</b>
                    <span class="pill" :class="STATUS_CLASS[r.status]" style="margin-left:8px">{{ t(STATUS_LABEL[r.status]) }}</span>
                    <div style="font-size:12px;color:var(--mut);margin-top:3px">{{ r.phone || '-' }} • {{ r.guest_count }} {{ t('reservation.guests') }}</div>
                    <div style="font-size:13px;margin-top:4px">🕒 {{ r.reservation_at }}<span v-if="r.table"> • 🪑 {{ r.table.name }}</span></div>
                    <div v-if="r.note" style="font-size:12.5px;color:var(--mut);margin-top:4px">📝 {{ r.note }}</div>
                    <div v-if="r.advance > 0" style="font-size:12.5px;margin-top:4px">{{ t('reservation.advanceLabel') }} {{ money(r.advance) }}</div>
                </div>
            </div>
            <div v-if="r.status === 'reserved'" class="btnrow" style="margin-top:10px">
                <button class="btn sm" style="flex:1" @click="seat(r)">{{ t('reservation.seatButton') }}</button>
                <button class="btn sm ghost" style="flex:0 0 auto;padding:0 14px" @click="openEdit(r)">{{ t('common.edit') }}</button>
            </div>
            <div v-if="r.status === 'reserved'" class="btnrow" style="margin-top:8px">
                <button class="btn sm ghost" style="flex:1;color:var(--rose);border-color:var(--rose)" @click="cancelReservation(r)">{{ t('common.cancel') }}</button>
                <button class="btn sm ghost" style="flex:1" @click="markNoShow(r)">{{ t('reservation.noShowButton') }}</button>
            </div>
        </div>
        <div v-if="!reservations.length" class="empty"><div class="big">📅</div>{{ t('reservation.noReservations') }}</div>

        <Sheet v-model="sheet" :title="editing ? t('common.edit') : t('reservation.addReservation')">
            <div class="field"><label>{{ t('reservation.name') }}</label><input v-model="form.name"></div>
            <div class="field"><label>{{ t('reservation.phone') }}</label><input v-model="form.phone" inputmode="tel" placeholder="01XXXXXXXXX"></div>
            <div class="field"><label>{{ t('reservation.dateTime') }}</label><input v-model="form.reservation_at" type="datetime-local"></div>
            <div class="field">
                <label>{{ t('reservation.guestCount') }}</label>
                <input v-model.number="form.guest_count" type="number" min="1">
            </div>
            <div class="field">
                <label>{{ t('reservation.table') }} <span style="color:var(--dim);font-weight:400">{{ t('stock.optional') }}</span></label>
                <select v-model="form.restaurant_table_id">
                    <option value="">{{ t('damage.selectPlaceholder') }}</option>
                    <option v-for="tb in freeTables" :key="tb.id" :value="tb.id">{{ tb.name }}</option>
                </select>
            </div>
            <div class="field"><label>{{ t('reservation.note') }} <span style="color:var(--dim);font-weight:400">{{ t('stock.optional') }}</span></label><input v-model="form.note"></div>
            <div class="field">
                <label>{{ t('reservation.advanceLabel') }} <span style="color:var(--dim);font-weight:400">{{ t('stock.optional') }}</span></label>
                <input v-model="form.advance" type="number" min="0">
            </div>
            <div v-if="form.errors.name" style="color:var(--rose);font-size:12px;margin-bottom:10px">{{ form.errors.name }}</div>
            <button class="btn" :disabled="form.processing" @click="save">{{ form.processing ? '...' : t('stock.save') }}</button>
            <button class="btn ghost" style="margin-top:10px" @click="sheet = false">{{ t('common.cancel') }}</button>
        </Sheet>
    </AppLayout>
</template>
