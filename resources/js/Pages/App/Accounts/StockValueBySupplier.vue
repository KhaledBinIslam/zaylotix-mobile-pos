<script setup>
/**
 * The stock value split by supplier — Khaled (Baby Park): "oi stock e kon
 * supplier tekhe koto tk er product nicche eta dekhate hobe details."
 * Each supplier with the taka value of their goods still on the shelf,
 * expandable to the articles behind it. Lot-tracked shops are valued lot
 * by lot; everyone else by the article's own supplier; what neither
 * covers is shown plainly as "supplier unknown". Under a shared stock
 * pool the figure is business-wide and identical on every branch — the
 * same rule the stock value card itself follows.
 */
import { Head, Link, router } from '@inertiajs/vue3';
import { ref } from 'vue';
import AppLayout from '@/Layouts/AppLayout.vue';
import { useI18n } from '@/composables/useI18n';

const props = defineProps({
    rows: Array, total: Number, pooledWith: { type: Array, default: () => [] }, usesLots: Boolean,
    period: { type: String, default: 'all' }, from: String, to: String, receivedSince: String,
    totals: { type: Object, default: () => ({ received: 0, sold: 0, soldCost: 0 }) },
});
const { t } = useI18n();
const money = (n) => '৳' + Math.round(n).toLocaleString('en-IN');

// period toggle for the received/sold figures (stock-in-hand is always "now");
// same query contract as the online orders dashboard
const customOpen = ref(props.period === 'custom');
const customFrom = ref(props.from || '');
const customTo = ref(props.to || '');
function go(query) {
    router.get(route('app.accounts.stockValueBySupplier'), query, { preserveScroll: true, preserveState: true });
}
function pick(p) {
    customOpen.value = false;
    go(p === 'all' ? {} : { period: p });
}
function applyCustom() {
    if (!customFrom.value) return;
    go({ period: 'custom', from: customFrom.value, ...(customTo.value ? { to: customTo.value } : {}) });
}
</script>

<template>
    <Head :title="t('acc.supplierStock.title')" />
    <AppLayout active="more">
        <div class="pgttl">🚚 {{ t('acc.supplierStock.title') }}</div>
        <div class="pgsub">{{ usesLots ? t('acc.supplierStock.subLots') : t('acc.supplierStock.subArticle') }}</div>

        <Link :href="route('app.accounts.stockValue')" class="btn ghost sm" style="margin-bottom:14px;display:inline-block;text-decoration:none">← {{ t('acc.supplierStock.backToStockValue') }}</Link>

        <div class="card" style="background:var(--greenSoft);margin-bottom:14px;text-align:center">
            <div style="color:var(--mut);font-size:13px">{{ t('acc.stockValue') }}</div>
            <div style="font-size:26px;font-weight:850;color:var(--green)">{{ money(total) }}</div>
            <div v-if="pooledWith.length" style="font-size:12px;color:var(--mut);margin-top:4px">{{ t('acc.supplierStock.pooledNote', { shops: pooledWith.join(', ') }) }}</div>
        </div>

        <div style="display:flex;gap:6px;flex-wrap:wrap;margin-bottom:8px">
            <button v-for="p in ['all', 'today', '7d', 'month']" :key="p" class="btn sm" :class="period === p && !customOpen ? '' : 'ghost'" style="width:auto;padding:4px 12px" @click="pick(p)">{{ t('supplierReport.period.' + p) }}</button>
            <button class="btn sm" :class="customOpen || period === 'custom' ? '' : 'ghost'" style="width:auto;padding:4px 12px" @click="customOpen = !customOpen">{{ t('supplierReport.period.custom') }}</button>
        </div>
        <div v-if="customOpen" style="display:flex;gap:8px;align-items:center;flex-wrap:wrap;margin-bottom:10px">
            <input v-model="customFrom" type="date" style="width:auto">
            <span>→</span>
            <input v-model="customTo" type="date" :min="customFrom" style="width:auto">
            <button class="btn sm" style="width:auto;padding:4px 12px" @click="applyCustom">{{ t('supplierReport.apply') }}</button>
        </div>
        <div style="display:flex;gap:8px;margin-bottom:8px">
            <div class="card" style="flex:1;text-align:center;padding:8px"><div style="font-size:11px;color:var(--mut)">{{ period === 'all' ? t('supplierReport.everReceived') : t('supplierReport.received') }}</div><b>{{ money(period === 'all' ? totals.everCost : totals.received) }}</b></div>
            <div class="card" style="flex:1;text-align:center;padding:8px"><div style="font-size:11px;color:var(--mut)">{{ t('supplierReport.sold') }}</div><b>{{ money(totals.sold) }}</b></div>
        </div>
        <div v-if="period === 'all'" style="font-size:11.5px;color:var(--mut);margin-bottom:4px">{{ t('supplierReport.everNote') }}</div>
        <div v-else-if="receivedSince" style="font-size:11.5px;color:var(--mut);margin-bottom:4px">{{ t('supplierReport.receivedSince', { date: receivedSince.slice(0, 10) }) }}</div>
        <div style="font-size:11.5px;color:var(--mut);margin-bottom:10px">{{ t('supplierReport.soldNote') }}</div>

        <div v-if="rows.length" class="card" style="padding:0;margin-bottom:14px">
            <details v-for="(r, i) in rows" :key="i" :style="i > 0 ? 'border-top:1px solid var(--line)' : ''">
                <summary class="row" style="cursor:pointer">
                    <div class="ava">{{ r.unknown ? '❔' : '🚚' }}</div>
                    <div class="mid">
                        <b :style="r.unknown ? 'color:var(--mut)' : ''">{{ r.unknown ? t('acc.supplierStock.unknown') : r.name }}</b>
                        <span>{{ r.qty }} {{ t('stock.pieces') }} · {{ r.products.length }} {{ t('acc.supplierStock.articles') }}</span>
                    </div>
                    <div class="end"><b style="color:var(--green)">{{ money(r.value) }}</b></div>
                </summary>
                <div style="display:grid;grid-template-columns:repeat(3,1fr);gap:6px;padding:8px 14px;font-size:12px;background:var(--panel2)">
                    <div v-if="period === 'all'"><div style="color:var(--mut)">{{ t('supplierReport.everReceived') }}</div><b>{{ money(r.everCost) }}</b><div style="color:var(--mut)">{{ r.everQty }} {{ t('stock.pieces') }}</div><div style="color:var(--dim);font-size:11px">{{ t('supplierReport.recordedPart', { qty: r.receivedQty, amount: money(r.received) }) }}</div></div>
                    <div v-else><div style="color:var(--mut)">{{ t('supplierReport.received') }}</div><b>{{ money(r.received) }}</b><div style="color:var(--mut)">{{ r.receivedQty }} {{ t('stock.pieces') }}</div></div>
                    <div><div style="color:var(--mut)">{{ t('supplierReport.inStock') }}</div><b style="color:var(--green)">{{ money(r.value) }}</b><div style="color:var(--mut)">{{ r.qty }} {{ t('stock.pieces') }}</div></div>
                    <div><div style="color:var(--mut)">{{ t('supplierReport.sold') }}</div><b>{{ money(r.sold) }}</b><div style="color:var(--mut)">{{ r.soldQty }} {{ t('stock.pieces') }} · {{ t('supplierReport.soldCost') }} {{ money(r.soldCost) }}</div></div>
                </div>
                <div v-if="r.branches.length" style="padding:4px 14px 8px;font-size:12px">
                    <div style="color:var(--mut);margin-bottom:2px">{{ t('supplierReport.byBranch') }}</div>
                    <div v-for="b in r.branches" :key="b.shop_id" style="display:flex;justify-content:space-between"><span>🏬 {{ b.name }}</span><span>{{ b.qty }} {{ t('stock.pieces') }} — <b>{{ money(b.value) }}</b></span></div>
                </div>
                <div v-for="(p, j) in r.products" :key="j" style="display:flex;justify-content:space-between;gap:8px;padding:6px 14px 6px 48px;font-size:12.5px;border-top:1px dashed var(--line)">
                    <div>{{ p.emoji || '📦' }} <b>{{ p.name }}</b><span v-if="p.via === 'lot'" style="color:var(--mut);font-size:11px"> · {{ t('acc.supplierStock.viaLot') }}</span></div>
                    <div style="white-space:nowrap">{{ p.qty }} {{ t('stock.pieces') }} — <b>{{ money(p.value) }}</b></div>
                </div>
            </details>
        </div>
        <div v-else class="empty"><div class="big">📦</div>{{ t('acc.noStockYet') }}</div>
    </AppLayout>
</template>
