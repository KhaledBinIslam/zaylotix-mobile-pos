<script setup>
import { Head, router } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import Pagination from '@/Components/Pagination.vue';
import { useI18n } from '@/composables/useI18n';

const props = defineProps({ type: String, records: Object });
const { t } = useI18n();

const money = (n) => '৳' + Math.round(n).toLocaleString('en-IN');
const fmt = (dt) => new Date(dt).toLocaleString('en-GB', { day: '2-digit', month: 'short', hour: '2-digit', minute: '2-digit' });

function setTab(type) {
    router.get(route('app.stockHistory.index'), { type }, { preserveState: true });
}
</script>

<template>
    <Head :title="t('stockHistory.title')" />
    <AppLayout active="stockHistory">
        <div class="pgttl">{{ t('stockHistory.title') }}</div>
        <div class="pgsub">{{ t('stockHistory.subtitle') }}</div>

        <div class="tabbar" style="margin-bottom:14px">
            <button :class="{ on: type === 'damage' }" @click="setTab('damage')">{{ t('stockHistory.tabDamage') }}</button>
            <button :class="{ on: type === 'count' }" @click="setTab('count')">{{ t('stockHistory.tabCount') }}</button>
            <button :class="{ on: type === 'return' }" @click="setTab('return')">{{ t('stockHistory.tabReturn') }}</button>
        </div>

        <!-- damage: one row per write-off -->
        <template v-if="type === 'damage'">
            <div v-for="r in records.data" :key="r.id" class="row" style="cursor:default">
                <div class="ava">{{ r.product?.emoji || '🗑️' }}</div>
                <div class="mid">
                    <b>{{ r.product?.name || '—' }} × {{ r.qty }}</b>
                    <span>{{ r.reason || '—' }} • {{ fmt(r.created_at) }}</span>
                </div>
                <div class="end" style="color:var(--rose);font-weight:700">−{{ money(r.loss) }}</div>
            </div>
        </template>

        <!-- stock count: one row per reconciliation batch, expandable per-product changes -->
        <template v-else-if="type === 'count'">
            <details v-for="r in records.data" :key="r.id" class="card" style="margin-bottom:10px">
                <summary style="cursor:pointer;display:flex;justify-content:space-between;align-items:center">
                    <b>{{ r.changed }} {{ t('stockHistory.productsChanged') }}</b>
                    <span style="color:var(--mut);font-size:12.5px">{{ fmt(r.created_at) }}</span>
                </summary>
                <div v-for="c in r.changes" :key="c.product_id" style="display:flex;justify-content:space-between;padding:4px 0;font-size:12.5px;color:var(--mut);border-top:1px solid var(--line)">
                    <span>{{ c.product_name }}</span>
                    <span>{{ c.from }} → {{ c.to }}</span>
                </div>
            </details>
        </template>

        <!-- sales return: one row per return -->
        <template v-else>
            <div v-for="r in records.data" :key="r.id" class="row" style="cursor:default">
                <div class="ava">{{ r.product?.emoji || '↩️' }}</div>
                <div class="mid">
                    <b>{{ r.product?.name || '—' }} × {{ r.qty }}</b>
                    <span>{{ r.user?.name || '—' }} • {{ fmt(r.created_at) }}{{ r.phone ? ' • ' + r.phone : '' }}</span>
                </div>
                <div class="end" style="color:var(--rose);font-weight:700">−{{ money(r.refund) }}</div>
            </div>
        </template>

        <div v-if="!records.data.length" class="empty"><div class="big">📜</div>{{ t('stockHistory.empty') }}</div>
        <Pagination :links="records.links" />
    </AppLayout>
</template>
