<script setup>
import { Head, router } from '@inertiajs/vue3';
import { ref } from 'vue';
import AppLayout from '@/Layouts/AppLayout.vue';
import { useI18n } from '@/composables/useI18n';

const props = defineProps({
    from: String, to: String, preset: String,
    channels: Array, consolidated: Object, byChannel: Array,
});
const { t } = useI18n();

const money = (n) => '৳' + Math.round(n).toLocaleString('en-IN');

function setPreset(p) {
    router.get(route('app.onlineProfit.index'), { preset: p }, { preserveState: true });
}
const customFrom = ref(props.from);
const customTo = ref(props.to);
function applyCustom() {
    router.get(route('app.onlineProfit.index'), { preset: 'custom', from: customFrom.value, to: customTo.value }, { preserveState: true });
}

const statusLabel = (s) => t('onlineOrder.status.' + s);
</script>

<template>
    <Head :title="t('oprofit.title')" />
    <AppLayout active="onlineProfit">
        <div class="pgttl">{{ t('oprofit.title') }}</div>
        <div class="pgsub">{{ t('oprofit.subtitle') }}</div>

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

        <div class="sechead"><h2>{{ t('oprofit.consolidated') }}</h2></div>
        <div class="card" style="margin-bottom:10px">
            <div style="display:flex;justify-content:space-between;padding:3px 0"><span>{{ t('oprofit.revenue') }}</span><b>{{ money(consolidated.online.revenue) }}</b></div>
            <div style="display:flex;justify-content:space-between;padding:3px 0"><span>{{ t('oprofit.cogs') }}</span><b style="color:var(--rose)">− {{ money(consolidated.online.cogs) }}</b></div>
            <div style="display:flex;justify-content:space-between;padding:3px 0"><span>{{ t('oprofit.courierCost') }}</span><b style="color:var(--rose)">− {{ money(consolidated.online.courier_cost) }}</b></div>
            <div style="display:flex;justify-content:space-between;padding:3px 0"><span>{{ t('oprofit.adSpend') }}</span><b style="color:var(--rose)">− {{ money(consolidated.online.ad_spend) }}</b></div>
            <div style="display:flex;justify-content:space-between;padding:3px 0"><span>{{ t('oprofit.fees') }}</span><b style="color:var(--rose)">− {{ money(consolidated.online.platform_gateway_fees) }}</b></div>
            <div style="display:flex;justify-content:space-between;padding:3px 0"><span>{{ t('oprofit.damageLoss') }}</span><b style="color:var(--rose)">− {{ money(consolidated.online.damage_loss) }}</b></div>
            <div class="hr" style="margin:8px 0"></div>
            <div style="display:flex;justify-content:space-between;padding:3px 0;font-weight:700"><span>{{ t('oprofit.grossProfit') }}</span><b :style="consolidated.online.gross_profit < 0 ? 'color:var(--rose)' : 'color:var(--green)'">{{ money(consolidated.online.gross_profit) }}</b></div>
            <div style="display:flex;justify-content:space-between;padding:3px 0"><span>{{ t('oprofit.overhead') }}</span><b style="color:var(--rose)">− {{ money(consolidated.online.overhead) }}</b></div>
            <div style="display:flex;justify-content:space-between;padding:4px 0;font-weight:800;font-size:16px"><span>{{ t('oprofit.netProfit') }}</span><b :style="consolidated.online.net_profit < 0 ? 'color:var(--rose)' : 'color:var(--green)'">{{ money(consolidated.online.net_profit) }}</b></div>
            <template v-if="consolidated.unallocated_overhead > 0">
                <div class="hr" style="margin:8px 0"></div>
                <div style="display:flex;justify-content:space-between;padding:3px 0;font-size:12px;color:var(--mut)"><span>{{ t('oprofit.unallocatedOverhead') }}</span><b>{{ money(consolidated.unallocated_overhead) }}</b></div>
            </template>
            <div v-if="!consolidated.overhead_rule" style="font-size:12px;color:var(--gold);margin-top:8px">{{ t('oprofit.overheadHint') }}</div>
        </div>
        <div class="f2" style="margin-bottom:16px">
            <div class="card" style="text-align:center"><div style="color:var(--mut);font-size:12px">{{ t('oprofit.stockValue') }}</div><div style="font-size:18px;font-weight:800">{{ money(consolidated.stock_value) }}</div></div>
            <div class="card" style="text-align:center"><div style="color:var(--mut);font-size:12px">{{ t('oprofit.codPending') }}</div><div style="font-size:18px;font-weight:800;color:var(--gold)">{{ money(consolidated.cod_pending) }}</div></div>
        </div>

        <div class="sechead"><h2>{{ t('oprofit.byChannel') }}</h2></div>
        <div v-for="row in byChannel" :key="row.channel_id" class="card" style="margin-bottom:12px">
            <b>{{ row.channel_name }}</b>

            <div style="font-size:12px;color:var(--mut);margin-top:10px">{{ t('oprofit.operational') }}</div>
            <div style="display:flex;justify-content:space-between;padding:2px 0;font-size:13px"><span>{{ t('oprofit.ordersPlaced') }}</span><b>{{ row.orders_placed }}</b></div>
            <div style="display:flex;flex-wrap:wrap;gap:6px;margin-top:4px">
                <span v-for="(count, status) in row.status_breakdown" :key="status" class="pill" style="font-size:11px">{{ statusLabel(status) }}: {{ count }}</span>
            </div>

            <div class="hr" style="margin:10px 0"></div>
            <div style="font-size:12px;color:var(--mut)">{{ t('oprofit.financial') }}</div>
            <div style="display:flex;justify-content:space-between;padding:2px 0;font-size:13px"><span>{{ t('oprofit.delivered') }} / {{ t('oprofit.returned') }}</span><b>{{ row.delivered_count }} / {{ row.returned_count }}</b></div>
            <div style="display:flex;justify-content:space-between;padding:2px 0;font-size:13px"><span>{{ t('oprofit.unitsSold') }} / {{ t('oprofit.unitsReturned') }}</span><b>{{ row.units_sold }} / {{ row.units_returned }}</b></div>
            <div style="display:flex;justify-content:space-between;padding:2px 0;font-size:13px"><span>{{ t('oprofit.revenue') }}</span><b>{{ money(row.revenue) }}</b></div>
            <div style="display:flex;justify-content:space-between;padding:2px 0;font-size:13px"><span>{{ t('oprofit.cogs') }}</span><b style="color:var(--rose)">− {{ money(row.cogs) }}</b></div>
            <div style="display:flex;justify-content:space-between;padding:2px 0;font-size:13px"><span>{{ t('oprofit.courierCost') }}</span><b style="color:var(--rose)">− {{ money(row.courier_cost) }}</b></div>
            <div style="display:flex;justify-content:space-between;padding:2px 0;font-size:13px"><span>{{ t('oprofit.adSpend') }}</span><b style="color:var(--rose)">− {{ money(row.ad_spend) }}</b></div>
            <div style="display:flex;justify-content:space-between;padding:2px 0;font-size:13px"><span>{{ t('oprofit.fees') }}</span><b style="color:var(--rose)">− {{ money(row.platform_gateway_fees) }}</b></div>
            <div style="display:flex;justify-content:space-between;padding:2px 0;font-size:13px"><span>{{ t('oprofit.damageLoss') }}</span><b style="color:var(--rose)">− {{ money(row.damage_loss) }}</b></div>
            <div style="display:flex;justify-content:space-between;padding:4px 0;font-size:13px;font-weight:700"><span>{{ t('oprofit.grossProfit') }}</span><b :style="row.gross_profit < 0 ? 'color:var(--rose)' : 'color:var(--green)'">{{ money(row.gross_profit) }}</b></div>
            <div style="display:flex;justify-content:space-between;padding:2px 0;font-size:13px"><span>{{ t('oprofit.overhead') }}</span><b style="color:var(--rose)">− {{ money(row.overhead_share) }}</b></div>
            <div style="display:flex;justify-content:space-between;padding:4px 0;font-size:14px;font-weight:800"><span>{{ t('oprofit.netProfit') }}</span><b :style="row.net_profit < 0 ? 'color:var(--rose)' : 'color:var(--green)'">{{ money(row.net_profit) }}</b></div>

            <div class="hr" style="margin:10px 0"></div>
            <div style="display:flex;justify-content:space-between;padding:2px 0;font-size:12px;color:var(--mut)"><span>{{ t('oprofit.aov') }}</span><b>{{ row.aov !== null ? money(row.aov) : '—' }}</b></div>
            <div style="display:flex;justify-content:space-between;padding:2px 0;font-size:12px;color:var(--mut)"><span>{{ t('oprofit.roas') }}</span><b>{{ row.roas ?? '—' }}</b></div>
            <div style="display:flex;justify-content:space-between;padding:2px 0;font-size:12px;color:var(--mut)"><span>{{ t('oprofit.returnPct') }}</span><b>{{ row.return_pct !== null ? row.return_pct + '%' : '—' }}</b></div>
        </div>
        <div v-if="!byChannel.length" class="empty"><div class="big">📊</div>{{ t('channel.noChannels') }}</div>

        <div style="font-size:12px;color:var(--dim);margin-top:10px">{{ t('oprofit.financialNote') }}</div>
    </AppLayout>
</template>
