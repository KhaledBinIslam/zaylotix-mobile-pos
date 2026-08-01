<script setup>
import { Head, router, usePage } from '@inertiajs/vue3';
import { ref, computed } from 'vue';
import AppLayout from '@/Layouts/AppLayout.vue';
import { useI18n } from '@/composables/useI18n';

const props = defineProps({
    from: String, to: String, preset: String, stats: Object, sales: Array, topProducts: Array, bottomProducts: Array,
    expiringSoon: Array, cashierBreakdown: Array, restaurantBreakdown: Object, salesByType: Object, itemWisePurchases: Array,
    summary: Object, categoryReport: Object, discountReport: Object, wastageReport: Array, ratingReport: Object, heatmap: Object,
});
const { t } = useI18n();

const isOwner = computed(() => usePage().props.auth?.user?.role === 'owner');
// counted cash is a quick, live variance check the owner does by eye at
// shift-end — never persisted, just a convenience calc on top of what the
// server already computed as "expected"
const counted = ref({});
function variance(row) {
    const c = counted.value[row.user_id];
    if (c === undefined || c === '') return null;
    return Number(c) - row.expected_cash;
}

const money = (n) => '৳' + Math.round(n).toLocaleString('en-IN');

const customFrom = ref(props.from);
const customTo = ref(props.to);

function setPreset(p) {
    if (p !== 'custom') {
        router.get(route('app.reports'), { preset: p }, { preserveState: true });
    }
}

function applyCustomRange() {
    if (!customFrom.value || !customTo.value) return;
    router.get(route('app.reports'), { preset: 'custom', from: customFrom.value, to: customTo.value }, { preserveState: true });
}

function exportReport(kind, fmt) {
    window.open(route('app.export', kind) + '?format=' + fmt, '_blank');
}

function daysLeft(dateStr) {
    return Math.ceil((new Date(dateStr) - new Date()) / 86400000);
}
function urgencyCls(dateStr) {
    const d = daysLeft(dateStr);
    if (d <= 14) return 'rose';
    if (d <= 30) return 'gold';
    return 'mut';
}

const WEEKDAY_LABELS = ['রবি', 'সোম', 'মঙ্গল', 'বুধ', 'বৃহঃ', 'শুক্র', 'শনি'];
// only the hours a sale has ever actually landed in, across the whole grid —
// a 24-column table for a shop that only sells 10am-10pm is mostly empty space
const activeHours = computed(() => {
    if (!props.heatmap) return [];
    const hours = new Set();
    Object.values(props.heatmap).forEach((row) => {
        Object.entries(row).forEach(([h, cell]) => { if (cell.count > 0) hours.add(Number(h)); });
    });
    return [...hours].sort((a, b) => a - b);
});
const heatmapMax = computed(() => {
    if (!props.heatmap) return 0;
    return Math.max(1, ...Object.values(props.heatmap).flatMap((row) => Object.values(row).map((c) => c.count)));
});
function heatColor(count) {
    if (!count) return 'transparent';
    const intensity = Math.min(1, count / heatmapMax.value);
    return `rgba(31, 164, 99, ${0.15 + intensity * 0.7})`;
}
</script>

<template>
    <Head :title="t('nav.reports')" />
    <AppLayout active="more">
        <div class="pgttl">{{ t('more.reportsTitle') }}</div>
        <div class="pgsub">{{ t('rep.subtitle') }}</div>

        <div class="seg" style="margin-bottom:12px">
            <button :class="{ on: preset === 'today' }" @click="setPreset('today')">{{ t('rep.today') }}</button>
            <button :class="{ on: preset === 'week' }" @click="setPreset('week')">{{ t('rep.week') }}</button>
            <button :class="{ on: preset === 'month' }" @click="setPreset('month')">{{ t('rep.month') }}</button>
            <button :class="{ on: preset === 'year' }" @click="setPreset('year')">{{ t('rep.year') }}</button>
            <button :class="{ on: preset === 'custom' }" @click="setPreset('custom')">{{ t('rep.custom') }}</button>
        </div>

        <!-- one-click everything summary -->
        <div class="card" style="margin-bottom:14px">
            <div style="font-size:11px;font-weight:800;color:var(--dim);text-transform:uppercase;margin-bottom:8px">{{ t('rep.oneClickSummary') }}</div>
            <div style="display:flex;justify-content:space-between;padding:4px 0"><span>{{ t('rep.netProfit') }}</span><b :style="{ color: stats.net >= 0 ? 'var(--green)' : 'var(--rose)' }">{{ money(stats.net) }}</b></div>
            <div style="display:flex;justify-content:space-between;padding:4px 0"><span>{{ t('rep.customerDueTotal') }} ({{ summary.customer_due_count }})</span><b style="color:var(--rose)">{{ money(summary.customer_due_total) }}</b></div>
            <div v-if="summary.supplier_payable_total !== null" style="display:flex;justify-content:space-between;padding:4px 0"><span>{{ t('rep.supplierPayableTotal') }} ({{ summary.supplier_payable_count }})</span><b style="color:var(--gold)">{{ money(summary.supplier_payable_total) }}</b></div>
        </div>

        <div v-if="preset === 'custom'" class="card" style="margin-bottom:12px">
            <div class="f2" style="margin-bottom:10px">
                <div class="field" style="margin:0"><label>{{ t('rep.from') }}</label><input v-model="customFrom" type="date"></div>
                <div class="field" style="margin:0"><label>{{ t('rep.to') }}</label><input v-model="customTo" type="date"></div>
            </div>
            <button class="btn sm" style="width:100%" @click="applyCustomRange">{{ t('rep.view') }}</button>
        </div>

        <div style="text-align:center;font-size:12px;color:var(--dim);margin-bottom:12px">{{ from }} — {{ to }} • {{ stats.count }} {{ t('rep.bills') }}</div>

        <div class="grid2" style="margin-bottom:14px">
            <div class="stat gold"><div class="k">{{ t('rep.sales') }}</div><div class="v">{{ money(stats.salesAmt) }}</div></div>
            <div class="stat mint"><div class="k">{{ t('rep.netProfit') }}</div><div class="v">{{ money(stats.net) }}</div></div>
        </div>

        <div class="card" style="margin-bottom:14px">
            <div style="display:flex;justify-content:space-between;padding:7px 0;border-bottom:1px solid var(--line)"><span style="color:var(--mut)">{{ t('rep.sales') }}</span><b style="color:var(--green)">+{{ money(stats.salesAmt) }}</b></div>
            <div style="display:flex;justify-content:space-between;padding:7px 0;border-bottom:1px solid var(--line)"><span style="color:var(--mut)">{{ t('rep.cogs') }}</span><b style="color:var(--mut)">−{{ money(stats.cogs) }}</b></div>
            <div style="display:flex;justify-content:space-between;padding:7px 0;border-bottom:1px solid var(--line)"><span style="color:var(--mut)">{{ t('rep.grossProfit') }}</span><b style="color:var(--green)">{{ money(stats.grossProfit) }}</b></div>
            <div style="display:flex;justify-content:space-between;padding:7px 0;border-bottom:1px solid var(--line)"><span style="color:var(--mut)">{{ t('rep.expenses') }}</span><b style="color:var(--rose)">−{{ money(stats.exp) }}</b></div>
            <div style="display:flex;justify-content:space-between;padding:7px 0;border-bottom:1px solid var(--line)"><span style="color:var(--mut)">{{ t('rep.damage') }}</span><b style="color:var(--rose)">−{{ money(stats.dmg) }}</b></div>
            <div style="display:flex;justify-content:space-between;padding:7px 0;border-bottom:1px solid var(--line)"><span style="color:var(--mut)">{{ t('rep.returns') }}</span><b style="color:var(--rose)">−{{ money(stats.ret) }}</b></div>
            <div style="display:flex;justify-content:space-between;padding:10px 0 2px;font-size:18px;font-weight:850"><span>{{ t('rep.netProfitLabel') }}</span><b :style="{ color: stats.net >= 0 ? 'var(--green)' : 'var(--rose)' }">{{ money(stats.net) }}</b></div>
        </div>

        <button class="btn" @click="exportReport('pl', 'xlsx')">{{ t('rep.downloadExcel') }}</button>
        <button class="btn ghost" style="margin-top:10px" @click="exportReport('pl', 'csv')">CSV</button>

        <template v-if="expiringSoon">
            <div class="sechead"><h2>{{ t('rep.expiringSoon') }}</h2></div>
            <div v-if="expiringSoon.length" class="card" style="padding:0;margin-bottom:14px">
                <div v-for="(b, i) in expiringSoon" :key="b.id" class="row" style="cursor:default" :style="i > 0 ? 'border-top:1px solid var(--line)' : ''">
                    <div class="ava">{{ b.product?.emoji || '📦' }}</div>
                    <div class="mid">
                        <b>{{ b.product?.name }}</b>
                        <span>{{ t('stock.batchNo') }} {{ b.batch_no || '—' }} • {{ b.qty }} {{ t('stock.pieces') }}</span>
                    </div>
                    <div class="end">
                        <span class="pill" :class="urgencyCls(b.expiry_date)">{{ b.expiry_date }}</span>
                    </div>
                </div>
            </div>
            <div v-else class="empty" style="margin-bottom:14px"><div class="big">✅</div>{{ t('rep.nothingExpiringSoon') }}</div>
        </template>

        <div class="sechead"><h2>{{ t('rep.topProducts') }}</h2></div>
        <div v-if="topProducts.length" class="card" style="padding:0;margin-bottom:14px">
            <div v-for="(p, i) in topProducts" :key="p.product_name" class="row" style="cursor:default" :style="i > 0 ? 'border-top:1px solid var(--line)' : ''">
                <div class="ava" style="font-size:13px;font-weight:800">{{ i + 1 }}</div>
                <div class="mid">
                    <b>{{ p.product_name }}</b>
                    <span>{{ p.qty_sold }} {{ t('rep.unitsSold') }} • {{ t('rep.revenue') }} {{ money(p.revenue) }}</span>
                </div>
                <div class="end"><b :style="{ color: p.profit >= 0 ? 'var(--green)' : 'var(--rose)' }">{{ money(p.profit) }}</b></div>
            </div>
        </div>
        <div v-else class="empty" style="margin-bottom:14px"><div class="big">📦</div>{{ t('rep.noBills') }}</div>

        <template v-if="bottomProducts?.length">
            <div class="sechead"><h2>{{ t('rep.bottomProducts') }}</h2></div>
            <div class="card" style="padding:0;margin-bottom:14px">
                <div v-for="(p, i) in bottomProducts" :key="p.product_name" class="row" style="cursor:default" :style="i > 0 ? 'border-top:1px solid var(--line)' : ''">
                    <div class="ava" style="font-size:13px;font-weight:800">{{ i + 1 }}</div>
                    <div class="mid"><b>{{ p.product_name }}</b><span>{{ p.qty_sold }} {{ t('rep.unitsSold') }}</span></div>
                    <div class="end"><b>{{ money(p.revenue) }}</b></div>
                </div>
            </div>
        </template>

        <template v-if="salesByType && Object.keys(salesByType).length">
            <div class="sechead"><h2>{{ t('rep.salesByType') }}</h2></div>
            <div class="card" style="margin-bottom:14px">
                <div v-for="(row, type) in salesByType" :key="type" style="display:flex;justify-content:space-between;padding:5px 0">
                    <span>{{ type === 'wholesale' ? t('rep.wholesale') : t('rep.retail') }} <span style="color:var(--dim)">× {{ row.count }}</span></span>
                    <b>{{ money(row.total) }}</b>
                </div>
            </div>
        </template>

        <template v-if="itemWisePurchases?.length">
            <div class="sechead"><h2>{{ t('rep.itemWisePurchases') }}</h2></div>
            <div class="card" style="padding:0;margin-bottom:14px">
                <div v-for="(p, i) in itemWisePurchases" :key="p.product_name" class="row" style="cursor:default" :style="i > 0 ? 'border-top:1px solid var(--line)' : ''">
                    <div class="mid"><b>{{ p.product_name }}</b><span>{{ p.qty }} {{ t('rep.unitsPurchased') }}</span></div>
                    <div class="end"><b>{{ money(p.amount) }}</b></div>
                </div>
            </div>
        </template>

        <template v-if="isOwner && cashierBreakdown?.length">
            <div class="sechead"><h2>{{ t('rep.cashierBreakdown') }}</h2></div>
            <div class="card" style="margin-bottom:14px">
                <div style="font-size:12px;color:var(--mut);margin-bottom:10px">{{ t('rep.cashierBreakdownHint') }}</div>
                <div v-for="row in cashierBreakdown" :key="row.user_id" style="padding:10px 0;border-top:1px solid var(--line)" :style="cashierBreakdown[0] === row ? 'border-top:none' : ''">
                    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:6px">
                        <b>{{ row.name }}</b>
                        <b style="color:var(--gold)">{{ t('rep.expectedCash') }} {{ money(row.expected_cash) }}</b>
                    </div>
                    <div style="font-size:12px;color:var(--mut);margin-bottom:8px">
                        {{ t('rep.cashSales') }} {{ money(row.cash_sales) }} · {{ t('rep.cashCollected') }} {{ money(row.cash_due_collected) }} · {{ t('rep.cashRefunded') }} −{{ money(row.cash_returns) }}
                    </div>
                    <div style="display:flex;gap:8px;align-items:center">
                        <input v-model="counted[row.user_id]" type="number" :placeholder="t('rep.countedCash')" style="flex:1;margin:0">
                        <b v-if="variance(row) !== null" :style="{ color: Math.abs(variance(row)) < 0.01 ? 'var(--green)' : 'var(--rose)' }">
                            {{ variance(row) >= 0 ? '+' : '' }}{{ money(variance(row)) }}
                        </b>
                    </div>
                </div>
            </div>
        </template>

        <template v-if="restaurantBreakdown && (Object.keys(restaurantBreakdown.by_source).length || Object.keys(restaurantBreakdown.by_waiter).length)">
            <div class="sechead"><h2>{{ t('rep.restaurantBreakdown') }}</h2></div>
            <div class="card" style="margin-bottom:14px">
                <div v-if="Object.keys(restaurantBreakdown.by_source).length" style="margin-bottom:12px">
                    <div style="font-size:11px;font-weight:800;color:var(--dim);text-transform:uppercase;margin-bottom:6px">{{ t('rep.bySource') }}</div>
                    <div v-for="(row, name) in restaurantBreakdown.by_source" :key="name" style="display:flex;justify-content:space-between;padding:5px 0;font-size:13px">
                        <span>{{ name }} <span style="color:var(--dim)">× {{ row.count }}</span></span><b>{{ money(row.total) }}</b>
                    </div>
                </div>
                <div v-if="Object.keys(restaurantBreakdown.by_waiter).length">
                    <div style="font-size:11px;font-weight:800;color:var(--dim);text-transform:uppercase;margin-bottom:6px">{{ t('rep.byWaiter') }}</div>
                    <div v-for="(row, name) in restaurantBreakdown.by_waiter" :key="name" style="display:flex;justify-content:space-between;padding:5px 0;font-size:13px">
                        <span>{{ name }} <span style="color:var(--dim)">× {{ row.count }}</span></span><b>{{ money(row.total) }}</b>
                    </div>
                </div>
            </div>
        </template>

        <template v-if="categoryReport && Object.keys(categoryReport).length">
            <div class="sechead"><h2>{{ t('rep.categoryReport') }}</h2></div>
            <div class="card" style="padding:0;margin-bottom:14px">
                <div v-for="(row, name) in categoryReport" :key="name" class="row" style="cursor:default">
                    <div class="mid"><b>{{ name }}</b><span>{{ row.qty }} {{ t('rep.unitsSold') }}</span></div>
                    <div class="end"><b>{{ money(row.revenue) }}</b></div>
                </div>
            </div>
        </template>

        <template v-if="discountReport && discountReport.total > 0">
            <div class="sechead"><h2>{{ t('rep.discountReport') }}</h2></div>
            <div class="card" style="margin-bottom:14px">
                <div style="display:flex;justify-content:space-between;padding:5px 0"><span>{{ t('rep.overallDiscountLabel') }}</span><b>{{ money(discountReport.overall_discount) }}</b></div>
                <div style="display:flex;justify-content:space-between;padding:5px 0"><span>{{ t('rep.itemDiscountLabel') }}</span><b>{{ money(discountReport.item_discount) }}</b></div>
                <div style="display:flex;justify-content:space-between;padding:8px 0 2px;border-top:1px solid var(--line);margin-top:4px;font-weight:800"><span>{{ t('rep.totalDiscountLabel') }}</span><b style="color:var(--rose)">{{ money(discountReport.total) }}</b></div>
                <div style="font-size:12px;color:var(--dim);margin-top:6px">{{ t('rep.salesWithDiscount', { n: discountReport.sales_with_discount }) }}</div>
            </div>
        </template>

        <template v-if="wastageReport?.length">
            <div class="sechead"><h2>{{ t('rep.wastageReport') }}</h2></div>
            <div class="card" style="padding:0;margin-bottom:14px">
                <div v-for="(row, i) in wastageReport" :key="i" class="row" style="cursor:default" :style="i > 0 ? 'border-top:1px solid var(--line)' : ''">
                    <div class="mid"><b>{{ row.product_name }}</b><span>{{ row.reason }} • {{ row.qty }} {{ t('stock.pieces') }}</span></div>
                    <div class="end"><b style="color:var(--rose)">−{{ money(row.loss) }}</b></div>
                </div>
            </div>
        </template>

        <template v-if="ratingReport && ratingReport.count > 0">
            <div class="sechead"><h2>{{ t('rep.ratingReport') }}</h2></div>
            <div class="card" style="margin-bottom:14px">
                <div style="text-align:center;margin-bottom:10px">
                    <div style="font-size:28px;font-weight:850;color:var(--gold)">{{ ratingReport.average }} <span style="font-size:16px">★</span></div>
                    <div style="font-size:12px;color:var(--dim)">{{ t('rep.basedOnRatings', { n: ratingReport.count }) }}</div>
                </div>
                <template v-if="ratingReport.low.length">
                    <div style="font-size:11px;font-weight:800;color:var(--dim);text-transform:uppercase;margin-bottom:6px">{{ t('rep.lowRatings') }}</div>
                    <div v-for="(r, i) in ratingReport.low" :key="i" style="padding:8px 0" :style="i > 0 ? 'border-top:1px solid var(--line)' : ''">
                        <div style="display:flex;justify-content:space-between"><b>{{ r.invoice_no }}</b><span>{{ '★'.repeat(r.stars) }}{{ '☆'.repeat(5 - r.stars) }}</span></div>
                        <div v-if="r.comment" style="font-size:12.5px;color:var(--mut);margin-top:2px">{{ r.comment }}</div>
                    </div>
                </template>
            </div>
        </template>

        <template v-if="heatmap && activeHours.length">
            <div class="sechead"><h2>{{ t('rep.heatmap') }}</h2></div>
            <div class="card" style="margin-bottom:14px;overflow-x:auto">
                <div style="font-size:12px;color:var(--mut);margin-bottom:10px">{{ t('rep.heatmapHint') }}</div>
                <table style="border-collapse:collapse;font-size:10.5px">
                    <thead>
                        <tr>
                            <th style="padding:2px 6px"></th>
                            <th v-for="h in activeHours" :key="h" style="padding:2px 4px;font-weight:600;color:var(--dim)">{{ h }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="d in 7" :key="d">
                            <td style="padding:2px 6px;font-weight:700;color:var(--dim);white-space:nowrap">{{ WEEKDAY_LABELS[d - 1] }}</td>
                            <td v-for="h in activeHours" :key="h" :title="heatmap[d - 1][h].count + ' bill • ' + money(heatmap[d - 1][h].total)" :style="{ background: heatColor(heatmap[d - 1][h].count), width: '20px', height: '20px', textAlign: 'center', border: '1px solid var(--line)' }">
                                {{ heatmap[d - 1][h].count || '' }}
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </template>

        <div class="sechead"><h2>{{ t('rep.billList') }}</h2></div>
        <div v-for="s in sales" :key="s.id" class="row">
            <div class="ava">🧾</div>
            <div class="mid"><b>{{ s.invoice_no }}</b><span>{{ s.date }} • {{ s.time }}</span></div>
            <div class="end"><b>{{ money(s.total) }}</b></div>
        </div>
        <div v-if="!sales.length" class="empty"><div class="big">📊</div>{{ t('rep.noBills') }}</div>
    </AppLayout>
</template>
