<script setup>
import { Head, Link } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import Pagination from '@/Components/Pagination.vue';
import { useI18n } from '@/composables/useI18n';

const props = defineProps({ plans: Object, tab: String });
const { t } = useI18n();

const money = (n) => '৳' + Math.round(n).toLocaleString('en-IN');

function scheduledTotal(plan) {
    return (plan.items || []).reduce((sum, i) => sum + Number(i.amount), 0);
}
function totalPaid(plan) {
    return (plan.items || []).reduce((sum, i) => sum + Number(i.amount_paid), 0);
}
function remaining(plan) {
    return Math.max(0, scheduledTotal(plan) - totalPaid(plan));
}
function isOverdue(plan) {
    const today = new Date().toISOString().slice(0, 10);
    return (plan.items || []).some((i) => i.status === 'pending' && i.due_date.slice(0, 10) < today);
}

const statusLabel = (s) => ({ active: t('installment.statusActive'), completed: t('installment.statusCompleted'), cancelled: t('installment.statusCancelled') }[s] || s);
</script>

<template>
    <Head :title="t('installment.title')" />
    <AppLayout active="more">
        <div class="pgttl">📅 {{ t('installment.title') }}</div>
        <div class="pgsub">{{ t('installment.subtitle') }}</div>

        <div class="seg" style="margin-bottom:14px;overflow-x:auto">
            <Link :href="route('app.installments.index', { tab: 'active' })" :class="{ on: tab === 'active' }">{{ t('installment.statusActive') }}</Link>
            <Link :href="route('app.installments.index', { tab: 'overdue' })" :class="{ on: tab === 'overdue' }">{{ t('installment.tabOverdue') }}</Link>
            <Link :href="route('app.installments.index', { tab: 'booking' })" :class="{ on: tab === 'booking' }">{{ t('installment.tabBooking') }}</Link>
            <Link :href="route('app.installments.index', { tab: 'completed' })" :class="{ on: tab === 'completed' }">{{ t('installment.statusCompleted') }}</Link>
        </div>

        <Link v-for="plan in plans.data" :key="plan.id" :href="route('app.installments.show', plan.id)" class="row">
            <div class="ava">{{ plan.booking ? '📦' : '📅' }}</div>
            <div class="mid">
                <b>{{ plan.customer?.name }}</b><span v-if="plan.booking" class="pill mut" style="margin-left:6px;font-size:10px">{{ t('installment.bookingBadge') }}</span>
                <span :style="isOverdue(plan) ? 'color:var(--rose);font-weight:700' : ''">
                    {{ isOverdue(plan) ? t('installment.overdueTag') + ' • ' : '' }}{{ statusLabel(plan.status) }} • {{ t('installment.remainingLabel') }} {{ money(remaining(plan)) }}
                </span>
            </div>
            <div class="end">›</div>
        </Link>
        <div v-if="!plans.data.length" class="empty"><div class="big">{{ tab === 'booking' ? '📦' : '📅' }}</div>{{ tab === 'booking' ? t('installment.noBookings') : t('installment.noPlans') }}</div>
        <Pagination :links="plans.links" />
    </AppLayout>
</template>
