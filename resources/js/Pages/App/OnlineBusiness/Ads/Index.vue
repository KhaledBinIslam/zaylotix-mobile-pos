<script setup>
import { Head, useForm, router } from '@inertiajs/vue3';
import { ref, computed } from 'vue';
import AppLayout from '@/Layouts/AppLayout.vue';
import Sheet from '@/Components/Sheet.vue';
import { useI18n } from '@/composables/useI18n';

const props = defineProps({
    from: String, to: String, preset: String,
    channels: Array, adExpenses: Array, campaigns: Array, channelEconomics: Object, overheadRule: String,
});
const { t } = useI18n();

const money = (n) => '৳' + Math.round(n).toLocaleString('en-IN');

function setPreset(p) {
    router.get(route('app.ads.index'), { preset: p }, { preserveState: true });
}
const customFrom = ref(props.from);
const customTo = ref(props.to);
function applyCustom() {
    router.get(route('app.ads.index'), { preset: 'custom', from: customFrom.value, to: customTo.value }, { preserveState: true });
}

// --- add ad spend ---
const sheet = ref(false);
const currency = ref('bdt'); // 'bdt' | 'usd'
const splitMode = ref('percent'); // 'percent' | 'amount'
const form = useForm({
    campaign_tag: '', amount: '', amount_usd: '', exchange_rate: '', method: 'cash', memo: '',
    splits: [{ channel_id: props.channels[0]?.id ?? '', percent: 100, amount: '' }],
});

function openAdd() {
    form.reset();
    currency.value = 'bdt';
    splitMode.value = 'percent';
    form.splits = [{ channel_id: props.channels[0]?.id ?? '', percent: 100, amount: '' }];
    sheet.value = true;
}
function addSplitRow() {
    form.splits.push({ channel_id: props.channels[0]?.id ?? '', percent: 0, amount: '' });
}
function removeSplitRow(i) {
    form.splits.splice(i, 1);
}
function save() {
    const payload = { ...form.data() };
    if (currency.value === 'bdt') { payload.amount_usd = ''; payload.exchange_rate = ''; } else { payload.amount = ''; }
    payload.splits = form.splits.map((s) => splitMode.value === 'percent'
        ? { channel_id: s.channel_id, percent: s.percent }
        : { channel_id: s.channel_id, amount: s.amount });

    form.transform(() => payload).post(route('app.ads.store'), { onSuccess: () => (sheet.value = false) });
}

// --- overhead rule ---
const ruleForm = useForm({ rule: props.overheadRule || 'equal', percentages: props.channels.map((c) => ({ channel_id: c.id, percent: c.overhead_allocation_percent ?? 0 })) });
function saveRule() {
    ruleForm.put(route('app.ads.overheadRule'));
}

const totalRevenue = computed(() => props.channelEconomics?.total_revenue ?? 0);
</script>

<template>
    <Head :title="t('ads.title')" />
    <AppLayout active="ads">
        <div class="pgttl">{{ t('ads.title') }}</div>
        <div class="pgsub">{{ t('ads.subtitle') }}</div>

        <div class="seg" style="margin-bottom:12px">
            <button :class="{ on: preset === 'today' }" @click="setPreset('today')">{{ t('rep.today') }}</button>
            <button :class="{ on: preset === 'week' }" @click="setPreset('week')">{{ t('rep.week') }}</button>
            <button :class="{ on: preset === 'month' }" @click="setPreset('month')">{{ t('rep.month') }}</button>
            <button :class="{ on: preset === 'year' }" @click="setPreset('year')">{{ t('rep.year') }}</button>
            <button :class="{ on: preset === 'custom' }" @click="setPreset('custom')">{{ t('rep.custom') }}</button>
        </div>
        <div v-if="preset === 'custom'" class="card" style="margin-bottom:12px">
            <div class="f2">
                <div class="field"><label>{{ t('rep.from') }}</label><input v-model="customFrom" type="date"></div>
                <div class="field"><label>{{ t('rep.to') }}</label><input v-model="customTo" type="date"></div>
            </div>
            <button class="btn sm" @click="applyCustom">{{ t('rep.view') }}</button>
        </div>

        <button class="btn" style="margin-bottom:16px" @click="openAdd">{{ t('ads.add') }}</button>

        <div class="sechead"><h2>{{ t('ads.campaignPerformance') }}</h2></div>
        <div v-for="c in campaigns" :key="c.campaign_tag" class="card" style="margin-bottom:10px">
            <div style="display:flex;justify-content:space-between;align-items:center">
                <b>{{ c.campaign_tag }}</b>
                <b style="color:var(--rose)">{{ money(c.spend) }}</b>
            </div>
            <div class="hr" style="margin:8px 0"></div>
            <div style="display:flex;justify-content:space-between;padding:2px 0;font-size:13px"><span>{{ t('ads.orders') }} / {{ t('ads.delivered') }}</span><b>{{ c.orders }} / {{ c.delivered }}</b></div>
            <div style="display:flex;justify-content:space-between;padding:2px 0;font-size:13px"><span>{{ t('ads.revenue') }}</span><b>{{ money(c.revenue) }}</b></div>
            <div style="display:flex;justify-content:space-between;padding:2px 0;font-size:13px"><span>{{ t('ads.roas') }}</span><b>{{ c.roas ?? '—' }}</b></div>
            <div style="display:flex;justify-content:space-between;padding:2px 0;font-size:13px"><span>{{ t('ads.costPerOrder') }}</span><b>{{ c.cost_per_order !== null ? money(c.cost_per_order) : '—' }}</b></div>
            <div style="display:flex;justify-content:space-between;padding:2px 0;font-size:13px"><span>{{ t('ads.costPerDelivered') }}</span><b>{{ c.cost_per_delivered !== null ? money(c.cost_per_delivered) : '—' }}</b></div>
        </div>
        <div v-if="!campaigns.length" class="empty"><div class="big">📢</div>{{ t('ads.noCampaigns') }}</div>

        <div class="sechead"><h2>{{ t('ads.channelEconomics') }}</h2></div>
        <div v-for="row in channelEconomics.rows" :key="row.channel_id" class="card" style="margin-bottom:10px">
            <b>{{ row.channel_name }}</b>
            <div class="hr" style="margin:8px 0"></div>
            <div style="display:flex;justify-content:space-between;padding:2px 0;font-size:13px"><span>{{ t('ads.revenue') }}</span><b>{{ money(row.revenue) }}</b></div>
            <div style="display:flex;justify-content:space-between;padding:2px 0;font-size:13px"><span>{{ t('ads.grossProfit') }}</span><b>{{ money(row.gross_profit) }}</b></div>
            <div style="display:flex;justify-content:space-between;padding:2px 0;font-size:13px"><span>{{ t('ads.courierCharge') }}</span><b style="color:var(--rose)">− {{ money(row.courier_charge) }}</b></div>
            <div style="display:flex;justify-content:space-between;padding:2px 0;font-size:13px"><span>{{ t('ads.adSpend') }}</span><b style="color:var(--rose)">− {{ money(row.ad_spend) }}</b></div>
            <div style="display:flex;justify-content:space-between;padding:4px 0;font-size:13px;font-weight:700"><span>{{ t('ads.profitBeforeOverhead') }}</span><b :style="row.profit_before_overhead < 0 ? 'color:var(--rose)' : 'color:var(--green)'">{{ money(row.profit_before_overhead) }}</b></div>
            <template v-if="overheadRule">
                <div style="display:flex;justify-content:space-between;padding:2px 0;font-size:13px"><span>{{ t('ads.overheadShare') }}</span><b style="color:var(--rose)">− {{ money(row.overhead_share) }}</b></div>
                <div style="display:flex;justify-content:space-between;padding:4px 0;font-size:13px;font-weight:700"><span>{{ t('ads.profitAfterOverhead') }}</span><b :style="row.profit_after_overhead < 0 ? 'color:var(--rose)' : 'color:var(--green)'">{{ money(row.profit_after_overhead) }}</b></div>
            </template>
        </div>
        <div v-if="!overheadRule" class="card" style="margin-bottom:16px;background:var(--goldSoft);color:var(--gold);font-size:13px">{{ t('ads.ruleNotSet') }}</div>
        <div v-else class="card" style="margin-bottom:16px;font-size:13px;color:var(--mut)">{{ t('ads.totalOverhead') }}: {{ money(channelEconomics.total_overhead) }}</div>

        <div class="sechead"><h2>{{ t('ads.overheadRule') }}</h2></div>
        <div class="card" style="margin-bottom:16px">
            <div class="seg" style="margin-bottom:12px">
                <button :class="{ on: ruleForm.rule === 'equal' }" @click="ruleForm.rule = 'equal'">{{ t('ads.ruleEqual') }}</button>
                <button :class="{ on: ruleForm.rule === 'revenue_share' }" @click="ruleForm.rule = 'revenue_share'">{{ t('ads.ruleRevenueShare') }}</button>
                <button :class="{ on: ruleForm.rule === 'manual' }" @click="ruleForm.rule = 'manual'">{{ t('ads.ruleManual') }}</button>
            </div>
            <template v-if="ruleForm.rule === 'manual'">
                <div style="font-size:12px;color:var(--mut);margin-bottom:8px">{{ t('ads.manualPercentHint') }}</div>
                <div v-for="(p, i) in ruleForm.percentages" :key="p.channel_id" class="f2" style="margin-bottom:8px">
                    <div style="display:flex;align-items:center">{{ channels.find((c) => c.id === p.channel_id)?.name }}</div>
                    <input v-model.number="ruleForm.percentages[i].percent" type="number" min="0" max="100" step="0.01">
                </div>
            </template>
            <button class="btn sm" :disabled="ruleForm.processing" @click="saveRule">{{ t('ads.saveRule') }}</button>
        </div>

        <div class="sechead"><h2>{{ t('ads.history') }}</h2></div>
        <div v-for="x in adExpenses" :key="x.id" class="row">
            <div class="ava">📢</div>
            <div class="mid">
                <b>{{ x.campaign_tag }}</b>
                <span>{{ x.date }} • {{ x.channel_splits.map((s) => s.channel.name).join(', ') }}</span>
            </div>
            <div class="end"><b style="color:var(--rose)">− {{ money(x.amount) }}</b></div>
        </div>
        <div v-if="!adExpenses.length" class="empty"><div class="big">💸</div>{{ t('ads.noAdExpenses') }}</div>

        <Sheet v-model="sheet" :title="t('ads.addSheetTitle')">
            <div class="field">
                <label>{{ t('ads.campaignTag') }}</label>
                <input v-model="form.campaign_tag" :placeholder="t('ads.campaignTagPlaceholder')">
                <div v-if="form.errors.campaign_tag" style="color:var(--rose);font-size:12px;margin-top:6px">{{ form.errors.campaign_tag }}</div>
            </div>

            <div class="field">
                <label>{{ t('ads.currency') }}</label>
                <div class="seg">
                    <button type="button" :class="{ on: currency === 'bdt' }" @click="currency = 'bdt'">{{ t('ads.currencyBdt') }}</button>
                    <button type="button" :class="{ on: currency === 'usd' }" @click="currency = 'usd'">{{ t('ads.currencyUsd') }}</button>
                </div>
            </div>
            <div v-if="currency === 'bdt'" class="field">
                <label>{{ t('ads.amountBdt') }}</label>
                <input v-model="form.amount" type="number" min="0" step="0.01">
                <div v-if="form.errors.amount" style="color:var(--rose);font-size:12px;margin-top:6px">{{ form.errors.amount }}</div>
            </div>
            <div v-else class="f2">
                <div class="field">
                    <label>{{ t('ads.amountUsd') }}</label>
                    <input v-model="form.amount_usd" type="number" min="0" step="0.01">
                </div>
                <div class="field">
                    <label>{{ t('ads.exchangeRate') }}</label>
                    <input v-model="form.exchange_rate" type="number" min="0" step="0.01" placeholder="110">
                    <div v-if="form.errors.exchange_rate" style="color:var(--rose);font-size:12px;margin-top:6px">{{ form.errors.exchange_rate }}</div>
                </div>
            </div>

            <div class="field">
                <label>{{ t('exp.source') }}</label>
                <select v-model="form.method"><option value="cash">{{ t('exp.cash') }}</option><option value="bank">{{ t('purchase.methodBank') }}</option></select>
            </div>
            <div class="field"><label>{{ t('exp.description') }} <span style="color:var(--dim);font-weight:400">{{ t('stock.optional') }}</span></label><input v-model="form.memo"></div>

            <div class="field">
                <label>{{ t('ads.splitMode') }}</label>
                <div class="seg">
                    <button type="button" :class="{ on: splitMode === 'percent' }" @click="splitMode = 'percent'">{{ t('ads.splitByPercent') }}</button>
                    <button type="button" :class="{ on: splitMode === 'amount' }" @click="splitMode = 'amount'">{{ t('ads.splitByAmount') }}</button>
                </div>
            </div>
            <div v-for="(s, i) in form.splits" :key="i" class="f2" style="margin-bottom:8px;align-items:end">
                <div class="field" style="margin-bottom:0">
                    <select v-model="s.channel_id">
                        <option v-for="c in channels" :key="c.id" :value="c.id">{{ c.name }}</option>
                    </select>
                </div>
                <div style="display:flex;gap:6px">
                    <input v-if="splitMode === 'percent'" v-model.number="s.percent" type="number" min="0" max="100" step="0.01" style="flex:1">
                    <input v-else v-model.number="s.amount" type="number" min="0" step="0.01" style="flex:1">
                    <button v-if="form.splits.length > 1" type="button" class="btn sm rose" @click="removeSplitRow(i)">🗑️</button>
                </div>
            </div>
            <div v-if="form.errors.splits" style="color:var(--rose);font-size:12px;margin-bottom:8px">{{ form.errors.splits }}</div>
            <button type="button" class="btn sm ghost" style="margin-bottom:14px" @click="addSplitRow">{{ t('ads.addChannelSplit') }}</button>

            <button class="btn" :disabled="form.processing" @click="save">{{ form.processing ? '...' : t('stock.save') }}</button>
            <button class="btn ghost" style="margin-top:10px" @click="sheet = false">{{ t('common.cancel') }}</button>
        </Sheet>
    </AppLayout>
</template>
