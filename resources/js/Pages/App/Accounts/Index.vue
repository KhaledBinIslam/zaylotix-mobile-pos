<script setup>
import { Head, router, usePage } from '@inertiajs/vue3';
import { computed, ref, watch } from 'vue';
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
    router.patch(route('app.settings.vat'), { vat_mode: mode }, { preserveScroll: true });
}

const vatRateInput = ref(
    props.shop?.vat_mode === 'turnover' ? props.shop?.turnover_rate : props.shop?.vat_rate,
);
// re-syncs the input after switching mode (e.g. none -> full), so it shows
// that mode's own saved rate instead of whatever was last typed
watch(() => props.shop?.vat_mode, () => {
    vatRateInput.value = props.shop?.vat_mode === 'turnover' ? props.shop?.turnover_rate : props.shop?.vat_rate;
});
function saveVatRate() {
    router.patch(route('app.settings.vat'), {
        vat_mode: props.shop?.vat_mode,
        rate: vatRateInput.value,
    }, { preserveScroll: true });
}

const serviceChargeRate = ref(props.shop?.service_charge_rate ?? '');
function saveServiceCharge() {
    router.patch(route('app.settings.serviceCharge'), {
        service_charge_rate: serviceChargeRate.value === '' ? null : serviceChargeRate.value,
    }, { preserveScroll: true });
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
                <div v-if="shop?.vat_mode !== 'none'" style="display:flex;gap:8px;margin-top:10px;align-items:center">
                    <input v-model="vatRateInput" type="number" inputmode="decimal" min="0" max="100" step="0.5" :placeholder="t('acc.vatRateLabel')" style="flex:1;margin:0">
                    <span style="color:var(--mut);font-size:13px">%</span>
                    <button class="btn sm" style="width:auto;padding:0 16px" @click="saveVatRate">{{ t('stock.save') }}</button>
                </div>
            </div>
        </template>

        <template v-if="features.includes('restaurant_tables')">
            <div class="sechead"><h2>{{ t('acc.serviceChargeSection') }}</h2></div>
            <div class="card">
                <div style="font-size:12.5px;color:var(--mut);margin-bottom:10px">{{ t('acc.serviceChargeHint') }}</div>
                <div style="display:flex;gap:8px">
                    <input v-model="serviceChargeRate" type="number" inputmode="decimal" min="0" max="100" step="0.5" :placeholder="t('acc.serviceChargeOffPlaceholder')" style="flex:1;margin:0">
                    <button class="btn sm" style="width:auto;padding:0 16px" @click="saveServiceCharge">{{ t('stock.save') }}</button>
                </div>
            </div>
        </template>
    </AppLayout>
</template>
