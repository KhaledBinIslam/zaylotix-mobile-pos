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
import { Head, Link } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import { useI18n } from '@/composables/useI18n';

const props = defineProps({ rows: Array, total: Number, pooledWith: { type: Array, default: () => [] }, usesLots: Boolean });
const { t } = useI18n();
const money = (n) => '৳' + Math.round(n).toLocaleString('en-IN');
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
                <div v-for="(p, j) in r.products" :key="j" style="display:flex;justify-content:space-between;gap:8px;padding:6px 14px 6px 48px;font-size:12.5px;border-top:1px dashed var(--line)">
                    <div>{{ p.emoji || '📦' }} <b>{{ p.name }}</b><span v-if="p.via === 'lot'" style="color:var(--mut);font-size:11px"> · {{ t('acc.supplierStock.viaLot') }}</span></div>
                    <div style="white-space:nowrap">{{ p.qty }} {{ t('stock.pieces') }} — <b>{{ money(p.value) }}</b></div>
                </div>
            </details>
        </div>
        <div v-else class="empty"><div class="big">📦</div>{{ t('acc.noStockYet') }}</div>
    </AppLayout>
</template>
