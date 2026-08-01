<script setup>
import { Head, useForm } from '@inertiajs/vue3';
import { ref, computed } from 'vue';
import AppLayout from '@/Layouts/AppLayout.vue';
import Sheet from '@/Components/Sheet.vue';
import Pagination from '@/Components/Pagination.vue';
import { useI18n } from '@/composables/useI18n';

defineProps({ transactions: Object, cash: Number, bank: Number });
const { t } = useI18n();

const money = (n) => '৳' + Math.round(n).toLocaleString('en-IN');

const TYPE_META = {
    deposit: { icon: '⬇️', cls: 'mint' },
    withdraw: { icon: '⬆️', cls: 'rose' },
    cash_to_bank: { icon: '🏦', cls: 'sky' },
    bank_to_cash: { icon: '💵', cls: 'sky' },
    bank_to_bank: { icon: '🔁', cls: 'mut' },
};
function meta(type) {
    return TYPE_META[type] || { icon: '💰', cls: 'mut' };
}
function label(type) {
    return t('cl.' + type.replace(/_([a-z])/g, (_, c) => c.toUpperCase()));
}

const sheet = ref(false);
const form = useForm({ type: 'deposit', amount: '', from_label: '', to_label: '', note: '' });
const isTransfer = computed(() => form.type === 'bank_to_bank');
function save() {
    form.post(route('app.cashLedger.store'), { onSuccess: () => { sheet.value = false; form.reset(); } });
}
</script>

<template>
    <Head :title="t('cl.title')" />
    <AppLayout active="more">
        <div class="pgttl">{{ t('cl.title') }}</div>
        <div class="pgsub">{{ t('cl.subtitle') }}</div>

        <div class="f2" style="margin-bottom:16px">
            <div class="card" style="text-align:center">
                <div style="color:var(--mut);font-size:13px">{{ t('cl.cashInHand') }}</div>
                <div style="font-size:22px;font-weight:850;color:var(--green)">{{ money(cash) }}</div>
            </div>
            <div class="card" style="text-align:center">
                <div style="color:var(--mut);font-size:13px">{{ t('cl.bankBalance') }}</div>
                <div style="font-size:22px;font-weight:850;color:var(--green)">{{ money(bank) }}</div>
            </div>
        </div>

        <button class="btn" style="margin-bottom:16px" @click="sheet = true">{{ t('cl.addTransaction') }}</button>

        <div v-for="x in transactions.data" :key="x.id" class="row">
            <div class="ava pill" :class="meta(x.type).cls" style="border-radius:12px;padding:0;width:42px;height:42px;font-size:18px">{{ meta(x.type).icon }}</div>
            <div class="mid">
                <b>{{ label(x.type) }}</b>
                <span>
                    <template v-if="x.from_label || x.to_label">{{ x.from_label }}<template v-if="x.from_label && x.to_label"> → </template>{{ x.to_label }} • </template>
                    {{ x.note ? x.note + ' • ' : '' }}{{ x.user?.name || '' }} • {{ x.date }}
                </span>
            </div>
            <div class="end"><b>{{ money(x.amount) }}</b></div>
        </div>
        <div v-if="!transactions.data.length" class="empty"><div class="big">💰</div>{{ t('cl.noTransactions') }}</div>
        <Pagination :links="transactions.links" />

        <Sheet v-model="sheet" :title="t('cl.addTransaction')">
            <div class="field">
                <label>{{ t('cl.type') }}</label>
                <select v-model="form.type">
                    <option value="deposit">{{ t('cl.deposit') }}</option>
                    <option value="withdraw">{{ t('cl.withdraw') }}</option>
                    <option value="cash_to_bank">{{ t('cl.cashToBank') }}</option>
                    <option value="bank_to_cash">{{ t('cl.bankToCash') }}</option>
                    <option value="bank_to_bank">{{ t('cl.bankToBank') }}</option>
                </select>
            </div>
            <div class="field">
                <label>{{ t('cl.amount') }}</label>
                <input v-model="form.amount" type="number">
                <div v-if="form.errors.amount" style="color:var(--rose);font-size:12px;margin-top:6px">{{ form.errors.amount }}</div>
            </div>
            <template v-if="isTransfer">
                <div class="f2">
                    <div class="field"><label>{{ t('cl.fromLabel') }}</label><input v-model="form.from_label"></div>
                    <div class="field"><label>{{ t('cl.toLabel') }}</label><input v-model="form.to_label"></div>
                </div>
                <div style="font-size:12px;color:var(--mut);margin-bottom:10px">{{ t('cl.bankToBankHint') }}</div>
            </template>
            <div class="field"><label>{{ t('cl.note') }}</label><input v-model="form.note"></div>
            <button class="btn" :disabled="form.processing" @click="save">{{ form.processing ? '...' : t('cl.save') }}</button>
            <button class="btn ghost" style="margin-top:10px" @click="sheet = false">{{ t('common.cancel') }}</button>
        </Sheet>
    </AppLayout>
</template>
