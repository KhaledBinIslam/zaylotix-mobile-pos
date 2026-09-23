<script setup>
import { Head } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import Pagination from '@/Components/Pagination.vue';
import { useI18n } from '@/composables/useI18n';

defineProps({ periods: Object });
const { t } = useI18n();

const money = (n) => '৳' + Math.round(n).toLocaleString('en-IN');

// variance was already being calculated and saved on close, just never
// shown anywhere - this is the one screen where an owner can actually see
// it: negative means less cash was counted than the ledger says there
// should be (shortage), positive means more (overage)
function varianceInfo(p) {
    const v = Number(p.variance);
    if (v === 0) return { label: t('workPeriod.matched'), cls: 'green', text: money(0) };
    if (v < 0) return { label: t('workPeriod.shortage'), cls: 'rose', text: '−' + money(Math.abs(v)) };
    return { label: t('workPeriod.overage'), cls: 'gold', text: '+' + money(v) };
}
function fmt(dt) {
    return new Date(dt).toLocaleString('en-GB', { day: '2-digit', month: 'short', hour: '2-digit', minute: '2-digit' });
}
</script>

<template>
    <Head :title="t('workPeriod.historyTitle')" />
    <AppLayout active="shifts">
        <div class="pgttl">{{ t('workPeriod.historyTitle') }}</div>
        <div class="pgsub">{{ t('workPeriod.historySubtitle') }}</div>

        <div v-for="p in periods.data" :key="p.id" class="card" style="margin-bottom:10px">
            <div style="display:flex;justify-content:space-between;align-items:flex-start">
                <div>
                    <b>{{ fmt(p.opened_at) }} — {{ fmt(p.closed_at) }}</b>
                    <div style="color:var(--mut);font-size:12.5px;margin-top:2px">{{ t('workPeriod.openedBy') }} {{ p.opened_by_user?.name || '—' }}</div>
                </div>
                <div :style="{ color: `var(--${varianceInfo(p).cls})`, fontWeight: 800, textAlign: 'right' }">
                    {{ varianceInfo(p).text }}
                    <div style="font-size: 11px; font-weight: 600">{{ varianceInfo(p).label }}</div>
                </div>
            </div>
            <div style="display:flex;justify-content:space-between;padding-top:8px;margin-top:8px;border-top:1px solid var(--line);font-size:12.5px;color:var(--mut)">
                <span>{{ t('workPeriod.openingCash') }}: {{ money(p.opening_cash) }}</span>
                <span>{{ t('workPeriod.closingCash') }}: {{ money(p.closing_cash) }}</span>
            </div>
        </div>
        <div v-if="!periods.data.length" class="empty"><div class="big">🕒</div>{{ t('workPeriod.empty') }}</div>
        <Pagination :links="periods.links" />
    </AppLayout>
</template>
