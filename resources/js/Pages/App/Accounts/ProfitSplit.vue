<script setup>
import { Head, Link, router } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import AppLayout from '@/Layouts/AppLayout.vue';
import HowToHint from '@/Components/HowToHint.vue';
import { useI18n } from '@/composables/useI18n';

const props = defineProps({
    from: String, to: String, preset: String,
    split: Object, products: { type: Array, default: () => [] },
    unallocatedDiscount: { type: Object, default: () => ({ offline: 0, online: 0, total: 0 }) },
    hasOnline: { type: Boolean, default: false },
});

const { t } = useI18n();
const money = (n) => (Number(n) < 0 ? '-' : '') + '৳' + Math.abs(Math.round(Number(n) || 0)).toLocaleString('en-IN');
const qty = (n) => (Math.round((Number(n) || 0) * 100) / 100).toLocaleString('en-IN');
const tone = (n) => (Number(n) >= 0 ? 'var(--green)' : 'var(--rose)');

const showCustom = ref(props.preset === 'custom');
const customFrom = ref(props.from);
const customTo = ref(props.to);
function setPreset(p) {
    showCustom.value = p === 'custom';
    if (p === 'custom') {
        router.get(route('app.accounts.profit'), { preset: 'custom', from: customFrom.value, to: customTo.value }, { preserveState: true });
        return;
    }
    router.get(route('app.accounts.profit'), { preset: p }, { preserveState: true });
}

const q = ref('');
const shown = computed(() => {
    const needle = q.value.trim().toLowerCase();
    return props.products.filter((p) => !needle || p.product_name.toLowerCase().includes(needle));
});

// product profits are before any bill-level discount (a coupon, the POS overall discount, an online order's overall
// discount belong to the bill, not to one product) — so the table adds it as its own line and the grand total agrees
const sumOf = (key) => props.products.reduce((s, p) => s + Number(p[key] || 0), 0);
const afterDiscount = (channel) => sumOf(channel + '_profit') - Number(props.unallocatedDiscount[channel] || 0);
</script>

<template>
    <Head :title="t('accProfit.title')" />
    <AppLayout active="more">
        <Link :href="route('app.accounts')" class="btn ghost sm" style="margin-bottom:12px;display:inline-block;text-decoration:none">← {{ t('common.back') }}</Link>
        <div class="pgttl">{{ t('accProfit.title') }}</div>
        <div class="pgsub">{{ t('accProfit.subtitle') }}</div>
        <HowToHint :text="t('accProfit.how')" />

        <div class="seg" style="margin-bottom:12px">
            <button :class="{ on: !showCustom && preset === 'today' }" @click="setPreset('today')">{{ t('rep.today') }}</button>
            <button :class="{ on: !showCustom && preset === 'week' }" @click="setPreset('week')">{{ t('rep.week') }}</button>
            <button :class="{ on: !showCustom && preset === 'month' }" @click="setPreset('month')">{{ t('rep.month') }}</button>
            <button :class="{ on: !showCustom && preset === 'year' }" @click="setPreset('year')">{{ t('rep.year') }}</button>
            <button :class="{ on: showCustom }" @click="showCustom = true">{{ t('rep.custom') }}</button>
        </div>
        <div v-if="showCustom" class="card" style="margin-bottom:12px">
            <div class="f2" style="margin-bottom:10px">
                <div class="field" style="margin:0"><label>{{ t('rep.from') }}</label><input v-model="customFrom" type="date"></div>
                <div class="field" style="margin:0"><label>{{ t('rep.to') }}</label><input v-model="customTo" type="date"></div>
            </div>
            <button class="btn sm" style="width:100%" @click="setPreset('custom')">{{ t('rep.view') }}</button>
        </div>
        <div style="font-size:12px;color:var(--mut);margin-bottom:10px">{{ from }} → {{ to }}</div>

        <!-- the three numbers: offline, online, total -->
        <div id="profit-split" class="card" style="margin-bottom:14px">
            <div class="row" style="padding:6px 0">
                <div class="ava">🏪</div>
                <div class="mid"><b>{{ t('accProfit.offline') }}</b><span>{{ t('accProfit.salesLine', { n: split.offline.bills, sales: money(split.offline.net_sales) }) }}</span></div>
                <div class="end"><b data-testid="profit-offline" :style="{ color: tone(split.offline.profit) }">{{ money(split.offline.profit) }}</b></div>
            </div>
            <div v-if="hasOnline || split.online.bills > 0" class="row" style="padding:6px 0">
                <div class="ava">🛵</div>
                <div class="mid"><b>{{ t('accProfit.online') }}</b><span>{{ t('accProfit.salesLine', { n: split.online.bills, sales: money(split.online.net_sales) }) }}</span></div>
                <div class="end"><b data-testid="profit-online" :style="{ color: tone(split.online.profit) }">{{ money(split.online.profit) }}</b></div>
            </div>
            <div class="hr" style="margin:8px 0"></div>
            <div style="display:flex;justify-content:space-between;align-items:center;font-size:19px;font-weight:800">
                <span>{{ t('accProfit.total') }}</span>
                <b data-testid="profit-total" :style="{ color: tone(split.total.profit) }">{{ money(split.total.profit) }}</b>
            </div>
            <div style="font-size:11px;color:var(--dim);margin-top:6px">{{ t('accProfit.beforeExpenses') }}</div>
            <div v-if="hasOnline" style="font-size:11px;color:var(--dim);margin-top:4px">{{ t('accProfit.onlineNote') }}</div>
        </div>

        <!-- product by product -->
        <div class="sechead"><h2>{{ t('accProfit.byProduct') }}</h2><span class="link">{{ products.length }}</span></div>
        <div class="field" style="margin-bottom:10px"><input v-model="q" type="search" :placeholder="t('accProfit.searchProduct')"></div>
        <div v-if="!products.length" class="card" style="text-align:center;color:var(--mut)">{{ t('accProfit.empty') }}</div>
        <div v-for="p in shown" :key="p.product_name" class="card" style="margin-bottom:8px">
            <div style="display:flex;justify-content:space-between;align-items:baseline;gap:8px">
                <b style="min-width:0;overflow-wrap:anywhere">{{ p.product_name }}</b>
                <b :style="{ color: tone(p.total_profit), whiteSpace: 'nowrap' }">{{ money(p.total_profit) }}</b>
            </div>
            <div style="display:flex;justify-content:space-between;font-size:12px;color:var(--mut);margin-top:4px">
                <span>🏪 {{ qty(p.offline_qty) }} {{ t('accProfit.pcs') }}</span>
                <span :style="{ color: tone(p.offline_profit) }">{{ money(p.offline_profit) }}</span>
            </div>
            <div v-if="hasOnline || p.online_qty !== 0" style="display:flex;justify-content:space-between;font-size:12px;color:var(--mut);margin-top:2px">
                <span>🛵 {{ qty(p.online_qty) }} {{ t('accProfit.pcs') }}</span>
                <span :style="{ color: tone(p.online_profit) }">{{ money(p.online_profit) }}</span>
            </div>
            <div style="font-size:11px;color:var(--dim);margin-top:2px">{{ t('accProfit.totalQty') }}: {{ qty(p.total_qty) }} · {{ t('accProfit.revenue') }}: {{ money(p.total_revenue) }}</div>
        </div>

        <!-- the bridge from "sum of products" to the three numbers above -->
        <div v-if="products.length" class="card" style="margin-top:6px;background:var(--surface2)">
            <div style="font-size:12px;font-weight:800;color:var(--dim);margin-bottom:6px">{{ t('accProfit.reconcile') }}</div>
            <div style="display:flex;justify-content:space-between;padding:2px 0"><span>{{ t('accProfit.sumOfProducts') }}</span><b>{{ money(sumOf('total_profit')) }}</b></div>
            <div style="display:flex;justify-content:space-between;padding:2px 0;color:var(--rose)"><span>{{ t('accProfit.billDiscounts') }}</span><b>− {{ money(unallocatedDiscount.total) }}</b></div>
            <div class="hr" style="margin:6px 0"></div>
            <div style="display:flex;justify-content:space-between;padding:2px 0;font-weight:800"><span>{{ t('accProfit.total') }}</span><b :style="{ color: tone(sumOf('total_profit') - unallocatedDiscount.total) }">{{ money(sumOf('total_profit') - unallocatedDiscount.total) }}</b></div>
        </div>
    </AppLayout>
</template>
