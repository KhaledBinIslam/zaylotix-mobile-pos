<script setup>
import { Head, router, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';
import AppLayout from '@/Layouts/AppLayout.vue';
import { useI18n } from '@/composables/useI18n';

const props = defineProps({
    shop: Object, cash: Number, bank: Number, stockValue: Number, receivable: Number,
    payable: Number, assets: Number, liabilities: Number, netWorth: Number,
    capital: Number, retained: Number, totalExpenses: Number, totalDamage: Number, totalReturns: Number,
});

const features = computed(() => usePage().props.features || []);
const { t } = useI18n();

const money = (n) => '৳' + Math.round(n).toLocaleString('en-IN');

function setVat(mode) {
    router.patch(route('app.settings.vat'), { vat_mode: mode });
}
</script>

<template>
    <Head :title="t('nav.accounts')" />
    <AppLayout active="more">
        <div class="pgttl">{{ t('nav.accounts') }}</div>
        <div class="pgsub">{{ t('acc.subtitle') }}</div>

        <div class="card" style="background:linear-gradient(135deg,var(--greenSoft),#fff);margin-bottom:16px;text-align:center">
            <div style="color:var(--mut);font-size:13px">{{ t('acc.businessValue') }}</div>
            <div style="font-size:32px;font-weight:850;color:var(--green);letter-spacing:-.5px">{{ money(netWorth) }}</div>
            <div style="font-size:12px;color:var(--dim)">{{ t('acc.assetsMinusLiabilities') }}</div>
        </div>

        <div class="sechead"><h2>{{ t('acc.assets') }}</h2><span class="link">{{ money(assets) }}</span></div>
        <div class="row"><div class="ava">💵</div><div class="mid"><b>{{ t('acc.cashInHand') }}</b></div><div class="end"><b style="color:var(--green)">{{ money(cash) }}</b></div></div>
        <div class="row"><div class="ava">🏦</div><div class="mid"><b>{{ t('acc.bankMfs') }}</b></div><div class="end"><b style="color:var(--green)">{{ money(bank) }}</b></div></div>
        <div class="row"><div class="ava">📦</div><div class="mid"><b>{{ t('acc.stockValue') }}</b></div><div class="end"><b style="color:var(--green)">{{ money(stockValue) }}</b></div></div>
        <div class="row"><div class="ava">🧾</div><div class="mid"><b>{{ t('acc.receivable') }}</b></div><div class="end"><b style="color:var(--green)">{{ money(receivable) }}</b></div></div>

        <div class="sechead"><h2>{{ t('acc.liabilities') }}</h2><span class="link" style="color:var(--rose)">{{ money(liabilities) }}</span></div>
        <div class="row"><div class="ava">📋</div><div class="mid"><b>{{ t('acc.payable') }}</b></div><div class="end"><b style="color:var(--rose)">{{ money(payable) }}</b></div></div>

        <div class="sechead"><h2>{{ t('acc.capital') }}</h2></div>
        <div class="row"><div class="ava">🤝</div><div class="mid"><b>{{ t('acc.ownerCapital') }}</b></div><div class="end"><b>{{ money(capital) }}</b></div></div>
        <div class="row"><div class="ava">📊</div><div class="mid"><b>{{ t('acc.retainedProfit') }}</b></div><div class="end"><b>{{ money(retained) }}</b></div></div>

        <div class="sechead"><h2>{{ t('acc.damageReturn') }}</h2></div>
        <div class="row"><div class="ava">🗑️</div><div class="mid"><b>{{ t('acc.damage') }}</b></div><div class="end"><b style="color:var(--rose)">{{ money(totalDamage) }}</b></div></div>
        <div class="row"><div class="ava">↩️</div><div class="mid"><b>{{ t('acc.returns') }}</b></div><div class="end"><b style="color:var(--rose)">{{ money(totalReturns) }}</b></div></div>

        <template v-if="features.includes('vat')">
            <div class="sechead"><h2>{{ t('acc.vatSection') }}</h2></div>
            <div class="card">
                <div style="font-size:12.5px;color:var(--mut);margin-bottom:10px">{{ t('acc.vatHint') }}</div>
                <div class="seg">
                    <button :class="{ on: shop?.vat_mode === 'none' }" @click="setVat('none')">{{ t('acc.vatNone') }}</button>
                    <button :class="{ on: shop?.vat_mode === 'turnover' }" @click="setVat('turnover')">{{ t('acc.vatTurnover') }}</button>
                    <button :class="{ on: shop?.vat_mode === 'full' }" @click="setVat('full')">{{ t('acc.vatFull') }}</button>
                </div>
            </div>
        </template>
    </AppLayout>
</template>
