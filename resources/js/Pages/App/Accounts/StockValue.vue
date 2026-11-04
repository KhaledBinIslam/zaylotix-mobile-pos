<script setup>
/**
 * The Accounts page's one stock-value figure, opened up — Khaled:
 * "serail dhore kontar stock mullo koto ache ... click korle details e
 * jate ese jai." Each article with its quantity and taka value, each
 * expandable to its sizes with the unit cost actually used (a size's own
 * group cost where one is set, the article's headline cost otherwise) —
 * the same rule AccountsController::stockValue() sums with, so the total
 * here always equals the card there.
 */
import { Head, Link } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import { useI18n } from '@/composables/useI18n';

const props = defineProps({ rows: Array, total: Number, totalQty: Number });
const { t } = useI18n();
const money = (n) => '৳' + Math.round(n).toLocaleString('en-IN');
</script>

<template>
    <Head :title="t('acc.stockValueTitle')" />
    <AppLayout active="more">
        <div class="pgttl">📦 {{ t('acc.stockValueTitle') }}</div>
        <div class="pgsub">{{ t('acc.stockValueDetailSub') }}</div>

        <Link :href="route('app.accounts')" class="btn ghost sm" style="margin-bottom:14px;display:inline-block;text-decoration:none">← {{ t('acc.backToAccounts') }}</Link>

        <div class="card" style="background:var(--greenSoft);margin-bottom:14px;text-align:center">
            <div style="color:var(--mut);font-size:13px">{{ t('acc.stockValue') }}</div>
            <div style="font-size:26px;font-weight:850;color:var(--green)">{{ money(total) }}</div>
            <div style="font-size:12px;color:var(--mut)">{{ totalQty }} {{ t('stock.pieces') }}</div>
        </div>

        <div v-if="rows.length" class="card" style="padding:0;margin-bottom:14px">
            <details v-for="(r, i) in rows" :key="i" :style="i > 0 ? 'border-top:1px solid var(--line)' : ''">
                <summary class="row" style="cursor:pointer">
                    <div class="ava">{{ r.emoji || '📦' }}</div>
                    <div class="mid"><b>{{ r.name }}</b><span>{{ r.qty }} {{ t('stock.pieces') }}</span></div>
                    <div class="end"><b style="color:var(--green)">{{ money(r.value) }}</b></div>
                </summary>
                <div v-for="(v, j) in r.variants" :key="j" style="display:flex;justify-content:space-between;gap:8px;padding:6px 14px 6px 48px;font-size:12.5px;border-top:1px dashed var(--line)">
                    <div><b>{{ v.label }}</b></div>
                    <div style="white-space:nowrap">{{ v.qty }} × {{ money(v.unit_cost) }} = <b>{{ money(v.value) }}</b></div>
                </div>
                <div v-if="!r.variants.length" style="padding:6px 14px 10px 48px;font-size:12px;color:var(--mut)">{{ t('acc.noVariantRows') }}</div>
            </details>
        </div>
        <div v-else class="empty"><div class="big">📦</div>{{ t('acc.noStockYet') }}</div>
    </AppLayout>
</template>
