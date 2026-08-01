<script setup>
import { Head, router, useForm } from '@inertiajs/vue3';
import { ref } from 'vue';
import AppLayout from '@/Layouts/AppLayout.vue';
import Sheet from '@/Components/Sheet.vue';
import { useI18n } from '@/composables/useI18n';

const props = defineProps({ month: String, rows: Array, totalPaidThisMonth: Number });
const { t } = useI18n();
const money = (n) => '৳' + Math.round(n).toLocaleString('en-IN');

const selectedMonth = ref(props.month);
function changeMonth() {
    router.get(route('app.payroll.index'), { month: selectedMonth.value }, { preserveState: true });
}

const paySheet = ref(false);
const paying = ref(null);
const form = useForm({ month: '', bonus: 0, advance_deduction: 0, net_paid: 0, method: 'cash' });

function openPay(row) {
    paying.value = row;
    form.reset();
    form.month = props.month;
    form.bonus = 0;
    form.advance_deduction = 0;
    form.net_paid = row.preview.suggested_net;
    paySheet.value = true;
}

function recompute() {
    if (!paying.value) return;
    const base = Number(paying.value.preview.suggested_net) + Number(form.bonus || 0);
    form.net_paid = Math.max(0, base - Number(form.advance_deduction || 0));
}

function submitPay() {
    router.post(route('app.payroll.pay', paying.value.id), form.data(), {
        onSuccess: () => { paySheet.value = false; },
    });
}
</script>

<template>
    <Head :title="t('pay.title')" />
    <AppLayout active="employees">
        <div class="pgttl">{{ t('pay.title') }}</div>
        <div class="pgsub">{{ t('pay.subtitle') }}</div>

        <input v-model="selectedMonth" type="month" style="margin-bottom:12px" @change="changeMonth">

        <div class="card" style="background:linear-gradient(135deg,var(--greenSoft),#fff);margin-bottom:16px;text-align:center">
            <div style="color:var(--mut);font-size:13px">{{ t('pay.totalPaid') }}</div>
            <div style="font-size:26px;font-weight:850;color:var(--green)">{{ money(totalPaidThisMonth) }}</div>
        </div>

        <div v-for="row in rows" :key="row.id" class="card" style="margin-bottom:12px">
            <div style="display:flex;justify-content:space-between;align-items:start;margin-bottom:8px">
                <div>
                    <b>{{ row.name }}</b>
                    <div style="font-size:12px;color:var(--mut)">{{ row.designation || '-' }} • {{ row.salary_type === 'monthly' ? t('emp.monthly') : t('emp.daily') }}</div>
                </div>
                <b style="color:var(--green)">{{ money(row.basic_salary) }}</b>
            </div>

            <div v-if="row.paid" class="row" style="cursor:default;background:var(--greenSoft);border-radius:10px">
                <div class="ava">✅</div>
                <div class="mid"><b>{{ t('pay.alreadyPaid') }}</b><span>{{ t('pay.paidOn') }}: {{ row.paid.paid_date }}</span></div>
                <div class="end"><b>{{ money(row.paid.net_paid) }}</b></div>
            </div>
            <template v-else>
                <div class="grid2" style="gap:6px;font-size:12px;margin-bottom:8px">
                    <div><span style="color:var(--mut)">{{ t('pay.presentDays') }}: </span><b>{{ row.preview.present_days }}</b></div>
                    <div><span style="color:var(--mut)">{{ t('pay.absentDays') }}: </span><b>{{ row.preview.absent_days }}</b></div>
                    <div><span style="color:var(--mut)">{{ t('pay.deduction') }}: </span><b>{{ money(row.preview.attendance_deduction) }}</b></div>
                    <div><span style="color:var(--mut)">{{ t('pay.outstandingAdvance') }}: </span><b>{{ money(row.outstanding_advance) }}</b></div>
                </div>
                <div style="font-size:13px;margin-bottom:10px"><span style="color:var(--mut)">{{ t('pay.suggestedNet') }}: </span><b style="color:var(--green)">{{ money(row.preview.suggested_net) }}</b></div>
                <button class="btn sm" style="width:100%" @click="openPay(row)">{{ t('pay.payNow') }}</button>
            </template>
        </div>
        <div v-if="!rows.length" class="empty"><div class="big">👥</div>{{ t('att.noActiveEmployees') }}</div>

        <Sheet v-model="paySheet" :title="paying ? t('pay.payNow') + ' — ' + paying.name : t('pay.payNow')">
            <div class="field">
                <label>{{ t('pay.bonus') }}</label>
                <input v-model="form.bonus" type="number" @input="recompute">
            </div>
            <div class="field">
                <label>{{ t('pay.deductAdvance') }} ({{ money(paying?.outstanding_advance || 0) }} {{ t('pay.outstandingAdvance').toLowerCase() }})</label>
                <input v-model="form.advance_deduction" type="number" @input="recompute">
            </div>
            <div class="field">
                <label>{{ t('pay.netToPay') }}</label>
                <input v-model="form.net_paid" type="number">
                <div v-if="form.errors.net_paid" style="color:var(--rose);font-size:12px;margin-top:6px">{{ form.errors.net_paid }}</div>
            </div>
            <div class="field">
                <label>{{ t('emp.method') }}</label>
                <select v-model="form.method"><option value="cash">{{ t('exp.cash') }}</option><option value="bank">{{ t('purchase.methodBank') }}</option></select>
            </div>
            <div v-if="form.errors.month" style="color:var(--rose);font-size:12px;margin-bottom:10px">{{ form.errors.month }}</div>
            <button class="btn" :disabled="form.processing" @click="submitPay">{{ form.processing ? '...' : t('pay.payNow') }}</button>
            <button class="btn ghost" style="margin-top:10px" @click="paySheet = false">{{ t('common.cancel') }}</button>
        </Sheet>
    </AppLayout>
</template>
