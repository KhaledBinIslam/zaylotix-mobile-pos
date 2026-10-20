<script setup>
import { Head, router, useForm, Link } from '@inertiajs/vue3';
import { ref, computed } from 'vue';
import AppLayout from '@/Layouts/AppLayout.vue';
import { useI18n } from '@/composables/useI18n';
import { useToast } from '@/composables/useToast';

/**
 * "ডেড স্টক ক্লিয়ারেন্স" — see docs/FIX_PLAN.md's design note. "মার্কডাউন"
 * here just sets products.discount_price (DeadStockController::markdown()),
 * the same field Stock/Index.vue's own edit form already exposes — this
 * page only adds a bulk, one-click way to set it across many slow-moving
 * products at once, picked by the server from real sales history.
 */
const props = defineProps({ deadStockDays: Number, cutoffDate: String, hasBatchTracking: Boolean, products: Array });
const { t } = useI18n();
const { toast } = useToast();

const money = (n) => '৳' + Math.round(n).toLocaleString('en-IN');

const daysForm = useForm({ dead_stock_days: props.deadStockDays });
function saveDays() {
    daysForm.patch(route('app.deadStock.updateDays'), { preserveScroll: true });
}

const selected = ref({});
const selectedIds = computed(() => Object.keys(selected.value).filter((id) => selected.value[id]).map(Number));
const selectedCount = computed(() => selectedIds.value.length);
function toggle(p) {
    if (selected.value[p.id]) delete selected.value[p.id];
    else selected.value[p.id] = true;
}

const percent = ref(20);
function applyMarkdown() {
    if (!selectedCount.value) {
        toast('❌ ' + t('deadStock.noneSelected'));
        return;
    }
    router.post(route('app.deadStock.markdown'), { percent: percent.value, product_ids: selectedIds.value }, {
        preserveScroll: true,
        onSuccess: () => (selected.value = {}),
    });
}
function clearMarkdown() {
    if (!selectedCount.value) {
        toast('❌ ' + t('deadStock.noneSelected'));
        return;
    }
    router.post(route('app.deadStock.clearMarkdown'), { product_ids: selectedIds.value }, {
        preserveScroll: true,
        onSuccess: () => (selected.value = {}),
    });
}
</script>

<template>
    <Head :title="t('deadStock.title')" />
    <AppLayout active="deadStock">
        <div class="pgttl">{{ t('deadStock.title') }}</div>
        <div class="pgsub">{{ t('deadStock.subtitle') }}</div>

        <div class="card" style="margin-bottom:16px">
            <div class="field" style="margin-bottom:0">
                <label>{{ t('deadStock.daysLabel') }}</label>
                <div style="display:flex;gap:8px;align-items:center">
                    <input v-model.number="daysForm.dead_stock_days" type="number" min="7" max="365" style="width:90px">
                    <span style="color:var(--mut)">{{ t('deadStock.daysSuffix') }}</span>
                    <button class="btn ghost sm" style="width:auto;padding:0 14px" :disabled="daysForm.processing" @click="saveDays">{{ t('stock.save') }}</button>
                </div>
            </div>
            <div style="font-size:12px;color:var(--mut);margin-top:8px">{{ t('deadStock.cutoffHint', { date: cutoffDate }) }}</div>
        </div>

        <div v-if="!products.length" class="empty"><div class="big">✅</div>{{ t('deadStock.noProducts') }}</div>

        <template v-else>
            <div v-for="p in products" :key="p.id" class="row" @click="toggle(p)" :style="selected[p.id] ? 'border-color:var(--gold);background:var(--goldSoft)' : ''">
                <div class="ava">{{ selected[p.id] ? '✅' : (p.emoji || '📦') }}</div>
                <div class="mid">
                    <b>{{ p.name }}</b>
                    <span>{{ money(p.price) }} • {{ t('deadStock.stockValue', { amount: money(p.stock_value) }) }}</span>
                    <span v-if="p.discount_price != null" style="color:var(--rose)">{{ t('deadStock.alreadyMarked', { price: money(p.discount_price) }) }}</span>
                    <span v-if="hasBatchTracking && p.expiry_date" style="color:var(--rose)">⏳ {{ t('deadStock.expiringOn', { date: p.expiry_date }) }}</span>
                </div>
            </div>

            <div v-if="selectedCount" style="height:88px"></div>
            <div v-if="selectedCount" class="posbar" style="display:flex;flex-direction:column;gap:8px;padding:10px">
                <div style="display:flex;gap:8px;align-items:center">
                    <input v-model.number="percent" type="number" min="1" max="90" style="width:70px">
                    <span>%</span>
                    <button class="btn sm" style="flex:1" @click="applyMarkdown">{{ t('deadStock.applyMarkdown', { n: selectedCount, pct: percent }) }}</button>
                </div>
                <button class="btn ghost sm" @click="clearMarkdown">{{ t('deadStock.clearMarkdown', { n: selectedCount }) }}</button>
            </div>

            <div class="card" style="margin-top:16px;font-size:12.5px;color:var(--mut)">
                {{ t('deadStock.bundleHint') }}
                <Link :href="route('app.promotions.index')" class="btn ghost sm" style="margin-top:8px">{{ t('nav.promotions') }} →</Link>
            </div>
        </template>
    </AppLayout>
</template>
