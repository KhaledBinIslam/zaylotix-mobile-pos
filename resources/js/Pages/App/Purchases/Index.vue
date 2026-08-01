<script setup>
import { Head, router } from '@inertiajs/vue3';
import { useI18n } from '@/composables/useI18n';
import AppLayout from '@/Layouts/AppLayout.vue';

const props = defineProps({ purchases: Array });
const { t } = useI18n();

const money = (n) => '৳' + Math.round(n).toLocaleString('en-IN');
const methodLabel = { cash: 'pay.cash', bank: 'purchase.methodBank', credit: 'pay.credit' };

function markReceived(p) {
    if (!confirm(t('purchase.confirmReceive', { amount: money(p.amount) }))) return;
    router.post(route('app.purchases.receive', p.id), {}, { preserveScroll: true });
}
</script>

<template>
    <Head :title="t('purchase.historyTitle')" />
    <AppLayout active="purchaseHistory">
        <div class="pgttl">{{ t('purchase.historyTitle') }}</div>
        <div class="pgsub">{{ t('purchase.historySubtitle') }}</div>

        <div v-for="p in purchases" :key="p.id" class="row" style="cursor:default">
            <div class="ava">{{ p.status === 'pending' ? '⏳' : '🚚' }}</div>
            <div class="mid">
                <b>{{ p.supplierModel?.name || p.supplier || t('purchase.noSupplier') }}</b>
                <span>{{ p.date }}{{ p.memo ? ' • ' + p.memo : '' }}{{ p.product ? ' • ' + p.product.name + ' ×' + p.qty : '' }}</span>
            </div>
            <div class="end">
                <b>{{ money(p.amount) }}</b>
                <span class="pill" :class="p.status === 'pending' ? 'gold' : 'mint'" style="margin-top:3px">
                    {{ p.status === 'pending' ? t('purchase.statusPending') : t(methodLabel[p.method]) }}
                </span>
                <button v-if="p.status === 'pending'" class="btn sm ghost" style="margin-top:6px" @click="markReceived(p)">{{ t('purchase.markReceived') }}</button>
            </div>
        </div>
        <div v-if="!purchases.length" class="empty"><div class="big">🚚</div>{{ t('purchase.noPurchases') }}</div>
    </AppLayout>
</template>
