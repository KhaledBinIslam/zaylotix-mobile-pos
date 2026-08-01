<script setup>
import { Head, useForm, router } from '@inertiajs/vue3';
import { ref } from 'vue';
import AppLayout from '@/Layouts/AppLayout.vue';
import Sheet from '@/Components/Sheet.vue';
import Pagination from '@/Components/Pagination.vue';
import { useI18n } from '@/composables/useI18n';

defineProps({ loans: Object, givenOutstanding: Number, takenOutstanding: Number });
const { t } = useI18n();

const money = (n) => '৳' + Math.round(n).toLocaleString('en-IN');

const sheet = ref(false);
const form = useForm({ party_name: '', phone: '', type: 'given', principal: '', method: 'cash', note: '' });
function save() {
    form.post(route('app.loans.store'), { onSuccess: () => { sheet.value = false; form.reset(); } });
}

const paySheet = ref(false);
const payingLoan = ref(null);
const payForm = useForm({ amount: '', method: 'cash' });
function openPay(loan) {
    payingLoan.value = loan;
    payForm.reset();
    payForm.amount = loan.outstanding;
    paySheet.value = true;
}
function submitPay() {
    router.post(route('app.loans.payments.store', payingLoan.value.id), payForm.data(), {
        onSuccess: () => { paySheet.value = false; },
    });
}
</script>

<template>
    <Head :title="t('loan.title')" />
    <AppLayout active="more">
        <div class="pgttl">{{ t('loan.title') }}</div>
        <div class="pgsub">{{ t('loan.subtitle') }}</div>

        <div class="f2" style="margin-bottom:16px">
            <div class="stat mint"><div class="k">{{ t('loan.given') }}</div><div class="v">{{ money(givenOutstanding) }}</div></div>
            <div class="stat rose"><div class="k">{{ t('loan.taken') }}</div><div class="v">{{ money(takenOutstanding) }}</div></div>
        </div>

        <button class="btn" style="margin-bottom:16px" @click="sheet = true">{{ t('loan.addLoan') }}</button>

        <div v-for="l in loans.data" :key="l.id" class="row">
            <div class="ava pill" :class="l.type === 'given' ? 'mint' : 'rose'" style="border-radius:12px;padding:0;width:42px;height:42px;font-size:18px">{{ l.type === 'given' ? '🤲' : '🙏' }}</div>
            <div class="mid">
                <b>{{ l.party_name }}</b>
                <span>{{ l.type === 'given' ? t('loan.given') : t('loan.taken') }} • {{ money(l.principal) }} • {{ l.date }}</span>
            </div>
            <div class="end">
                <b :style="{ color: l.type === 'given' ? 'var(--green)' : 'var(--rose)' }">{{ money(l.outstanding) }}</b>
                <div v-if="Number(l.outstanding) > 0">
                    <button class="link" style="font-size:12px" @click="openPay(l)">{{ t('loan.recordPayment') }}</button>
                </div>
                <div v-else style="font-size:11px;color:var(--mint)">{{ t('loan.fullyPaid') }}</div>
            </div>
        </div>
        <div v-if="!loans.data.length" class="empty"><div class="big">🤲</div>{{ t('loan.noLoans') }}</div>
        <Pagination :links="loans.links" />

        <Sheet v-model="sheet" :title="t('loan.addLoan')">
            <div class="field">
                <label>{{ t('loan.type') }}</label>
                <div class="seg">
                    <button :class="{ on: form.type === 'given' }" @click="form.type = 'given'">{{ t('loan.given') }}</button>
                    <button :class="{ on: form.type === 'taken' }" @click="form.type = 'taken'">{{ t('loan.taken') }}</button>
                </div>
            </div>
            <div class="field"><label>{{ t('loan.partyName') }}</label><input v-model="form.party_name"></div>
            <div class="field"><label>{{ t('loan.phone') }}</label><input v-model="form.phone"></div>
            <div class="f2">
                <div class="field">
                    <label>{{ t('loan.principal') }}</label>
                    <input v-model="form.principal" type="number">
                    <div v-if="form.errors.principal" style="color:var(--rose);font-size:12px;margin-top:6px">{{ form.errors.principal }}</div>
                </div>
                <div class="field">
                    <label>{{ t('loan.method') }}</label>
                    <select v-model="form.method"><option value="cash">{{ t('exp.cash') }}</option><option value="bank">{{ t('purchase.methodBank') }}</option></select>
                </div>
            </div>
            <div class="field"><label>{{ t('loan.note') }}</label><input v-model="form.note"></div>
            <button class="btn" :disabled="form.processing" @click="save">{{ form.processing ? '...' : t('loan.save') }}</button>
            <button class="btn ghost" style="margin-top:10px" @click="sheet = false">{{ t('common.cancel') }}</button>
        </Sheet>

        <Sheet v-model="paySheet" :title="t('loan.recordPayment')">
            <div class="field">
                <label>{{ t('loan.paymentAmount') }}</label>
                <input v-model="payForm.amount" type="number">
                <div v-if="payForm.errors.amount" style="color:var(--rose);font-size:12px;margin-top:6px">{{ payForm.errors.amount }}</div>
            </div>
            <div class="field">
                <label>{{ t('loan.method') }}</label>
                <select v-model="payForm.method"><option value="cash">{{ t('exp.cash') }}</option><option value="bank">{{ t('purchase.methodBank') }}</option></select>
            </div>
            <button class="btn" :disabled="payForm.processing" @click="submitPay">{{ payForm.processing ? '...' : t('loan.save') }}</button>
            <button class="btn ghost" style="margin-top:10px" @click="paySheet = false">{{ t('common.cancel') }}</button>
        </Sheet>
    </AppLayout>
</template>
