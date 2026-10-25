<script setup>
import { Head, Link, useForm } from '@inertiajs/vue3';
import { ref, computed } from 'vue';
import AppLayout from '@/Layouts/AppLayout.vue';
import { useI18n } from '@/composables/useI18n';

const props = defineProps({ courier: Object, stats: Object, pendingCodOrders: Array, settlements: Array });
const { t } = useI18n();

const money = (n) => '৳' + Math.round(n).toLocaleString('en-IN');

const selected = ref({});
const selectedCount = computed(() => Object.values(selected.value).filter(Boolean).length);
const selectedExpected = computed(() => props.pendingCodOrders.filter((o) => selected.value[o.id]).reduce((s, o) => s + o.cod_amount, 0));

function selectAll() {
    props.pendingCodOrders.forEach((o) => (selected.value[o.id] = true));
}
function clearSelect() {
    selected.value = {};
}

const settleForm = useForm({ order_ids: [], received_amount: '', note: '' });
const settleSheet = ref(false);
function openSettle() {
    settleForm.reset();
    settleForm.order_ids = Object.keys(selected.value).filter((id) => selected.value[id]).map(Number);
    settleForm.received_amount = selectedExpected.value;
    settleSheet.value = true;
}
function submitSettle() {
    settleForm.post(route('app.couriers.settle', props.courier.id), {
        onSuccess: () => { settleSheet.value = false; selected.value = {}; },
    });
}
</script>

<template>
    <Head :title="courier.name" />
    <AppLayout active="couriers">
        <div class="pgttl">{{ courier.name }}</div>
        <div class="pgsub">{{ t('courier.chargeType.' + courier.charge_type) }}</div>

        <Link :href="route('app.couriers.index')" class="btn ghost sm" style="margin-bottom:16px;display:inline-block;text-decoration:none">← {{ t('common.back') }}</Link>

        <div class="card" style="margin-bottom:14px">
            <div class="f2">
                <div><div class="k" style="color:var(--mut);font-size:12px">{{ t('courier.totalParcels') }}</div><div style="font-size:22px;font-weight:800">{{ stats.total }}</div></div>
                <div><div class="k" style="color:var(--mut);font-size:12px">{{ t('courier.avgDeliveryTime') }}</div><div style="font-size:22px;font-weight:800">{{ stats.avg_delivery_hours ?? '—' }}{{ stats.avg_delivery_hours ? ' ' + t('courier.hours') : '' }}</div></div>
            </div>
            <div class="hr" style="margin:10px 0"></div>
            <div style="display:flex;justify-content:space-between;padding:3px 0"><span>{{ t('onlineOrder.status.delivered') }}</span><b style="color:var(--green)">{{ stats.delivered }} ({{ stats.delivered_pct }}%)</b></div>
            <div style="display:flex;justify-content:space-between;padding:3px 0"><span>{{ t('onlineOrder.status.returned') }}</span><b style="color:var(--rose)">{{ stats.returned }} ({{ stats.returned_pct }}%)</b></div>
            <div style="display:flex;justify-content:space-between;padding:3px 0"><span>{{ t('onlineOrder.status.failed') }}</span><b style="color:var(--rose)">{{ stats.failed }} ({{ stats.failed_pct }}%)</b></div>
            <div class="hr" style="margin:10px 0"></div>
            <div style="display:flex;justify-content:space-between;padding:3px 0"><span>{{ t('courier.codCollected') }}</span><b>{{ money(stats.cod_collected) }}</b></div>
            <div style="display:flex;justify-content:space-between;padding:3px 0"><span>{{ t('courier.codPending') }}</span><b style="color:var(--gold)">{{ money(stats.cod_pending) }}</b></div>
            <div style="display:flex;justify-content:space-between;padding:3px 0"><span>{{ t('courier.chargesAccrued') }}</span><b>{{ money(stats.charges_accrued) }}</b></div>
        </div>

        <div class="sechead"><h2>{{ t('courier.pendingCod') }}</h2></div>
        <template v-if="pendingCodOrders.length">
            <div class="btnrow" style="margin-bottom:10px">
                <button class="btn ghost sm" style="flex:1" @click="selectAll">{{ t('due.selectAll') }}</button>
                <button class="btn ghost sm" style="flex:1" @click="clearSelect">{{ t('due.cancelSelect') }}</button>
            </div>
            <div v-for="o in pendingCodOrders" :key="o.id" class="row" @click="selected[o.id] = !selected[o.id]" :style="selected[o.id] ? 'border-color:var(--gold);background:var(--goldSoft)' : ''">
                <div class="ava" :style="selected[o.id] ? 'background:var(--gold);color:#fff' : ''">{{ selected[o.id] ? '✓' : '📦' }}</div>
                <div class="mid"><b>{{ o.order_number }}</b><span>{{ o.delivered_at?.slice(0, 10) }}</span></div>
                <div class="end"><b>{{ money(o.cod_amount) }}</b></div>
            </div>
            <button class="btn" style="width:100%;margin-top:10px" :disabled="!selectedCount" @click="openSettle">
                {{ selectedCount }} {{ t('courier.settleSelected') }} ({{ money(selectedExpected) }})
            </button>
        </template>
        <div v-else class="empty"><div class="big">✅</div>{{ t('courier.noPendingCod') }}</div>

        <div v-if="settlements.length" class="sechead" style="margin-top:20px"><h2>{{ t('courier.settlementHistory') }}</h2></div>
        <div v-for="s in settlements" :key="s.id" class="card" style="margin-bottom:8px">
            <div style="display:flex;justify-content:space-between">
                <span style="font-size:12px;color:var(--mut)">{{ s.settled_at?.slice(0, 10) }}<template v-if="s.settled_by_user"> • {{ s.settled_by_user.name }}</template></span>
                <b>{{ money(s.received_amount) }}</b>
            </div>
            <div v-if="Number(s.difference) !== 0" style="font-size:12px;margin-top:4px" :style="Number(s.difference) < 0 ? 'color:var(--rose)' : 'color:var(--green)'">
                {{ t('courier.expected') }} {{ money(s.expected_amount) }} — {{ t('courier.difference') }}: {{ Number(s.difference) > 0 ? '+' : '' }}{{ money(s.difference) }}
            </div>
        </div>

        <Sheet v-model="settleSheet" :title="t('courier.settleSheetTitle')">
            <div class="card" style="margin-bottom:14px;text-align:center">
                <div style="color:var(--mut);font-size:12px">{{ t('courier.expected') }}</div>
                <div style="font-size:24px;font-weight:800">{{ money(selectedExpected) }}</div>
            </div>
            <div class="field">
                <label>{{ t('courier.receivedAmount') }}</label>
                <input v-model.number="settleForm.received_amount" type="number" min="0">
                <div v-if="settleForm.errors.received_amount" style="color:var(--rose);font-size:12px;margin-top:6px">{{ settleForm.errors.received_amount }}</div>
            </div>
            <div v-if="Number(settleForm.received_amount) !== selectedExpected" class="card" style="margin-bottom:14px" :style="Number(settleForm.received_amount) < selectedExpected ? 'background:var(--roseSoft);color:var(--rose)' : 'background:var(--greenSoft);color:var(--green)'">
                {{ t('courier.difference') }}: {{ Number(settleForm.received_amount) - selectedExpected > 0 ? '+' : '' }}{{ money(Number(settleForm.received_amount) - selectedExpected) }}
            </div>
            <div class="field">
                <label>{{ t('courier.settleNote') }} <span style="color:var(--dim);font-weight:400">{{ t('stock.optional') }}</span></label>
                <input v-model="settleForm.note">
            </div>
            <button class="btn" :disabled="settleForm.processing" @click="submitSettle">{{ settleForm.processing ? '...' : t('courier.confirmSettle') }}</button>
            <button class="btn ghost" style="margin-top:10px" @click="settleSheet = false">{{ t('common.cancel') }}</button>
        </Sheet>
    </AppLayout>
</template>
