<script setup>
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import { ref } from 'vue';
import AppLayout from '@/Layouts/AppLayout.vue';
import Sheet from '@/Components/Sheet.vue';
import { useI18n } from '@/composables/useI18n';

const props = defineProps({ plan: Object, scheduledTotal: Number, totalPaid: Number, totalLateFees: Number, remainingBalance: Number });
const { t } = useI18n();

const money = (n) => '৳' + Math.round(n).toLocaleString('en-IN');
const fmtDate = (d) => d ? d.slice(0, 10) : '';
const today = new Date().toISOString().slice(0, 10);

const statusLabel = (s) => ({ active: t('installment.statusActive'), completed: t('installment.statusCompleted'), cancelled: t('installment.statusCancelled') }[s] || s);
function itemOverdue(item) {
    return item.status === 'pending' && item.due_date.slice(0, 10) < today;
}
function itemBalance(item) {
    return Math.max(0, Number(item.amount) + Number(item.late_fee_applied) - Number(item.amount_paid));
}

const paySheet = ref(false);
const payForm = useForm({ amount: props.remainingBalance, method: 'cash', installment_plan_id: props.plan.id });
function openPay() {
    payForm.amount = props.remainingBalance;
    paySheet.value = true;
}
function submitPay() {
    payForm.post(route('app.payments.store', props.plan.customer_id), {
        onSuccess: () => (paySheet.value = false),
    });
}

function cancelPlan() {
    if (!confirm(t('installment.cancelConfirm'))) return;
    router.post(route('app.installments.cancel', props.plan.id));
}

function markPickedUp() {
    router.post(route('app.installments.pickup', props.plan.id), {}, { preserveScroll: true });
}
function undoPickup() {
    router.post(route('app.installments.undoPickup', props.plan.id), {}, { preserveScroll: true });
}
</script>

<template>
    <Head :title="`${t('installment.title')} — ${plan.customer?.name}`" />
    <AppLayout active="more">
        <div class="pgttl">{{ plan.booking ? '📦' : '📅' }} {{ plan.customer?.name }}</div>
        <div class="pgsub">{{ statusLabel(plan.status) }}<span v-if="plan.booking"> • {{ t('installment.bookingBadge') }}</span></div>

        <div v-if="plan.booking" class="card" style="margin-bottom:14px" :style="plan.picked_up_at ? 'background:var(--greenSoft)' : 'background:var(--goldSoft)'">
            <div style="font-weight:700;margin-bottom:8px">{{ plan.picked_up_at ? t('installment.pickedUpLabel') : t('installment.notPickedUpLabel') }}</div>
            <button v-if="!plan.picked_up_at" class="btn sm" @click="markPickedUp">{{ t('installment.pickupButton') }}</button>
            <button v-else class="btn ghost sm" @click="undoPickup">{{ t('installment.undoPickupButton') }}</button>
        </div>

        <div class="card" style="margin-bottom:14px">
            <div v-if="plan.customer" style="margin-bottom:6px"><b>{{ t('service.customer') }}</b> {{ plan.customer.name }} ({{ plan.customer.phone }})</div>
            <div v-if="plan.sale" style="margin-bottom:6px"><Link :href="route('app.sales.show', plan.sale.id)">🧾 {{ plan.sale.invoice_no }}</Link></div>
            <div style="margin-bottom:6px"><b>{{ t('installment.downPayment') }}</b> {{ money(plan.down_payment) }}</div>
            <div v-if="plan.late_fee_type" style="margin-bottom:6px">
                <b>{{ t('installment.lateFeeType') }}</b>
                {{ plan.late_fee_type === 'percent' ? `${plan.late_fee_amount}%` : money(plan.late_fee_amount) }}
            </div>
        </div>

        <div class="card" style="margin-bottom:14px">
            <div style="display:flex;justify-content:space-between;padding:3px 0"><span>{{ t('installment.scheduledTotal') }}</span><b>{{ money(scheduledTotal) }}</b></div>
            <div style="display:flex;justify-content:space-between;padding:3px 0"><span>{{ t('installment.totalLateFees') }}</span><b>{{ money(totalLateFees) }}</b></div>
            <div style="display:flex;justify-content:space-between;padding:3px 0;color:var(--green)"><span>{{ t('installment.totalPaid') }}</span><b>{{ money(totalPaid) }}</b></div>
            <div style="display:flex;justify-content:space-between;padding:3px 0;font-size:17px;font-weight:800"><span>{{ t('installment.remainingLabel') }}</span><b style="color:var(--gold)">{{ money(remainingBalance) }}</b></div>
        </div>

        <div class="sechead"><h2>{{ t('installment.schedule') }}</h2></div>
        <div class="card" style="padding:0;margin-bottom:14px">
            <div v-for="(item, i) in plan.items" :key="item.id" class="row" style="cursor:default" :style="i > 0 ? 'border-top:1px solid var(--line)' : ''">
                <div class="mid">
                    <b :style="itemOverdue(item) ? 'color:var(--rose)' : ''">{{ fmtDate(item.due_date) }}{{ itemOverdue(item) ? ' — ' + t('installment.overdueTag') : '' }}</b>
                    <span>{{ money(item.amount) }}<template v-if="item.late_fee_applied > 0"> + {{ money(item.late_fee_applied) }} {{ t('installment.lateFeeLabel') }}</template></span>
                </div>
                <div class="end">
                    <b :style="{ color: item.status === 'paid' ? 'var(--green)' : 'var(--mut)' }">{{ item.status === 'paid' ? t('installment.paid') : money(itemBalance(item)) }}</b>
                </div>
            </div>
        </div>

        <template v-if="plan.status === 'active'">
            <button class="btn" style="margin-bottom:10px" @click="openPay">{{ t('installment.recordPaymentButton') }}</button>
            <button class="btn ghost" style="color:var(--rose);border-color:var(--rose)" @click="cancelPlan">{{ t('installment.cancelPlan') }}</button>
        </template>

        <template v-if="plan.payments?.length">
            <div class="sechead"><h2>{{ t('installment.paymentHistory') }}</h2></div>
            <div class="card" style="padding:0;margin-bottom:14px">
                <div v-for="(p, i) in plan.payments" :key="p.id" class="row" style="cursor:default" :style="i > 0 ? 'border-top:1px solid var(--line)' : ''">
                    <div class="mid"><b>{{ fmtDate(p.date) }}</b><span>{{ p.method }}</span></div>
                    <div class="end"><b style="color:var(--green)">{{ money(p.amount) }}</b></div>
                </div>
            </div>
        </template>

        <Sheet v-model="paySheet" :title="t('installment.recordPaymentButton')">
            <div class="field">
                <label>{{ t('installment.paymentAmount') }}</label>
                <input v-model.number="payForm.amount" type="number" step="0.01">
                <div v-if="payForm.errors.amount" style="color:var(--rose);font-size:12px;margin-top:6px">{{ payForm.errors.amount }}</div>
            </div>
            <div class="field">
                <label>{{ t('pos.payment') }}</label>
                <div class="seg">
                    <button :class="{ on: payForm.method === 'cash' }" @click="payForm.method = 'cash'">{{ t('pay.cash') }}</button>
                    <button :class="{ on: payForm.method === 'bkash' }" @click="payForm.method = 'bkash'">{{ t('pay.bkash') }}</button>
                    <button :class="{ on: payForm.method === 'nagad' }" @click="payForm.method = 'nagad'">{{ t('pay.nagad') }}</button>
                </div>
            </div>
            <button class="btn" :disabled="payForm.processing" @click="submitPay">{{ payForm.processing ? '...' : t('installment.recordPaymentButton') }}</button>
        </Sheet>
    </AppLayout>
</template>
