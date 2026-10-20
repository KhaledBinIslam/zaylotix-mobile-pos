<script setup>
import { Head, router } from '@inertiajs/vue3';
import { ref, computed } from 'vue';
import AppLayout from '@/Layouts/AppLayout.vue';
import { useI18n } from '@/composables/useI18n';
import { useToast } from '@/composables/useToast';

/**
 * "সিজন স্টক প্ল্যানার" — see docs/FIX_PLAN.md's design note. Every number
 * here comes straight from the server's read of last year's actual sales
 * (SeasonController::stockPlan()) — this page only lets the owner review/
 * adjust the suggested qty before turning the approved ones into real
 * pending purchase orders (SeasonController::draftPurchases()), since a
 * pending Purchase has no edit screen of its own once created.
 */
const props = defineProps({ season: Object, lastYearWindow: Object, suggestions: Array, suppliers: Array });
const { t } = useI18n();
const { toast } = useToast();

const money = (n) => '৳' + Math.round(n).toLocaleString('en-IN');

// editable qty per product_id, seeded from the server's suggestion —
// owner can raise/lower before submitting, or zero it out to skip a line
const qtyByProduct = ref(Object.fromEntries(props.suggestions.map((s) => [s.product_id, s.suggested_qty])));
const selected = ref(Object.fromEntries(props.suggestions.map((s) => [s.product_id, s.suggested_qty > 0])));
const supplierId = ref('');

const selectedCount = computed(() => Object.values(selected.value).filter(Boolean).length);
const totalCost = computed(() =>
    props.suggestions.reduce((sum, s) => (selected.value[s.product_id] ? sum + (Number(qtyByProduct.value[s.product_id]) || 0) * s.cost : sum), 0)
);

function createDraftPurchases() {
    const items = props.suggestions
        .filter((s) => selected.value[s.product_id] && Number(qtyByProduct.value[s.product_id]) > 0)
        .map((s) => ({ product_id: s.product_id, qty: Number(qtyByProduct.value[s.product_id]), cost: s.cost }));

    if (!items.length) {
        toast('❌ ' + t('seasonPlan.noneSelected'));
        return;
    }

    router.post(route('app.seasons.draftPurchases', props.season.id), { supplier_id: supplierId.value || null, items });
}
</script>

<template>
    <Head :title="t('seasonPlan.title')" />
    <AppLayout active="campaigns">
        <div class="pgttl">{{ t('seasonPlan.title') }}</div>
        <div class="pgsub">{{ season.name }}</div>

        <div v-if="!lastYearWindow" class="empty">
            <div class="big">📅</div>{{ t('seasonPlan.noDates') }}
        </div>

        <template v-else>
            <div class="card" style="margin-bottom:16px;font-size:12.5px;color:var(--mut)">
                {{ t('seasonPlan.windowHint', { start: lastYearWindow.start, end: lastYearWindow.end }) }}
            </div>

            <div v-if="!suggestions.length" class="empty"><div class="big">📦</div>{{ t('seasonPlan.noSales') }}</div>

            <template v-else>
                <div v-for="s in suggestions" :key="s.product_id" class="row" @click="selected[s.product_id] = !selected[s.product_id]" :style="selected[s.product_id] ? 'border-color:var(--gold);background:var(--goldSoft)' : ''">
                    <div class="ava">{{ selected[s.product_id] ? '✅' : (s.emoji || '📦') }}</div>
                    <div class="mid">
                        <b>{{ s.name }}</b>
                        <span>{{ t('seasonPlan.soldLastYear', { qty: s.qty_sold_last_year }) }} • {{ t('seasonPlan.currentStock', { qty: s.current_stock }) }}</span>
                    </div>
                    <div class="end" @click.stop>
                        <input
                            v-model.number="qtyByProduct[s.product_id]"
                            type="number"
                            min="0"
                            style="width:70px;text-align:center"
                            :disabled="!selected[s.product_id]"
                        >
                    </div>
                </div>

                <div class="card" style="margin:16px 0">
                    <div class="field">
                        <label>{{ t('seasonPlan.supplier') }} <span style="color:var(--dim);font-weight:400">{{ t('stock.optional') }}</span></label>
                        <select v-model="supplierId">
                            <option value="">{{ t('seasonPlan.noSupplier') }}</option>
                            <option v-for="sup in suppliers" :key="sup.id" :value="sup.id">{{ sup.name }}</option>
                        </select>
                    </div>
                    <div style="font-size:13px;color:var(--mut)">
                        {{ t('seasonPlan.selectedSummary', { n: selectedCount, amount: money(totalCost) }) }}
                    </div>
                </div>

                <button class="btn" :disabled="!selectedCount" @click="createDraftPurchases">
                    {{ t('seasonPlan.createDrafts', { n: selectedCount }) }}
                </button>
                <div style="font-size:11.5px;color:var(--mut);margin-top:8px">{{ t('seasonPlan.draftsHint') }}</div>
            </template>
        </template>
    </AppLayout>
</template>
