<script setup>
import { Head } from '@inertiajs/vue3';
import { ref, computed } from 'vue';
import AppLayout from '@/Layouts/AppLayout.vue';
import Pagination from '@/Components/Pagination.vue';
import { useI18n } from '@/composables/useI18n';

const props = defineProps({ logs: Object });
const { t } = useI18n();

// void/cancel actions are flagged rose (they undo revenue/stock), everything
// else is colored by which part of the shop it touched — purely visual, no
// meaning beyond making a long list scannable at a glance
const STYLES = {
    'sale.void': { icon: '❌', cls: 'rose' },
    'restaurant.cancel': { icon: '❌', cls: 'rose' },
    'restaurant.bill': { icon: '🍽️', cls: 'mint' },
    'purchase.record': { icon: '🛒', cls: 'gold' },
    'supplier.payment': { icon: '🚚', cls: 'gold' },
};
const PREFIX_STYLES = {
    product: { icon: '📦', cls: 'mint' },
    sale: { icon: '🧾', cls: 'sky' },
    payment: { icon: '💵', cls: 'mint' },
    customer: { icon: '👤', cls: 'mut' },
    staff: { icon: '👔', cls: 'mut' },
};
function styleFor(action) {
    if (STYLES[action]) return STYLES[action];
    const prefix = (action || '').split('.')[0];
    return PREFIX_STYLES[prefix] || { icon: '📋', cls: 'mut' };
}

// filters only the current (paginated) page — a full cross-page search
// would need a server round-trip; 50-per-page + newest-first already covers
// the common "what just happened" case this search box is for
const q = ref('');
const filtered = computed(() => props.logs.data.filter((l) =>
    !q.value || l.description.toLowerCase().includes(q.value.toLowerCase()) || (l.user?.name || '').toLowerCase().includes(q.value.toLowerCase())
));
</script>

<template>
    <Head :title="t('activity.title')" />
    <AppLayout active="activity">
        <div class="pgttl">{{ t('activity.title') }}</div>
        <div class="pgsub">{{ t('activity.subtitle') }}</div>

        <input v-model="q" :placeholder="t('activity.searchPlaceholder')" style="margin-bottom:12px">

        <div v-for="l in filtered" :key="l.id" class="row" style="cursor:default">
            <div class="ava pill" :class="styleFor(l.action).cls" style="border-radius:12px;padding:0;width:42px;height:42px;font-size:18px">{{ styleFor(l.action).icon }}</div>
            <div class="mid">
                <b>{{ l.description }}</b>
                <span>{{ l.user?.name || t('activity.system') }} • {{ l.created_at }}</span>
            </div>
        </div>
        <div v-if="!filtered.length" class="empty"><div class="big">📋</div>{{ logs.data.length ? t('activity.noResults') : t('activity.empty') }}</div>
        <Pagination :links="logs.links" />
    </AppLayout>
</template>
