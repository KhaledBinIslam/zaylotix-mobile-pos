<script setup>
import { Head, router } from '@inertiajs/vue3';
import { ref, onMounted, onBeforeUnmount } from 'vue';
import AppLayout from '@/Layouts/AppLayout.vue';
import { useI18n } from '@/composables/useI18n';
import { usePollingReload } from '@/composables/usePollingReload';

defineProps({ openOrders: Array, cookedOrders: Array });
const { t } = useI18n();

const tab = ref('open');

function cook(item) {
    router.post(route('app.kds.cook', item.id), {}, { preserveScroll: true });
}
function cookAll(order) {
    router.post(route('app.kds.cookAll', order.id), {}, { preserveScroll: true });
}

const SOURCE_ICON = { delivery: '🛵', takeaway: '🥡' };

// same live-polling pattern as Tables.vue/Order.vue — a cook's screen must
// reflect what a cashier just sent to the kitchen without a manual refresh
const { pollReload } = usePollingReload();
let pollTimer = null;
onMounted(() => {
    pollTimer = setInterval(() => {
        pollReload({ only: ['openOrders', 'cookedOrders'], preserveScroll: true });
    }, 8000);
});
onBeforeUnmount(() => clearInterval(pollTimer));
</script>

<template>
    <Head :title="t('kds.title')" />
    <AppLayout active="kds">
        <div class="pgttl">{{ t('kds.title') }}</div>
        <div class="pgsub">{{ t('kds.subtitle') }}</div>

        <div class="seg" style="margin-bottom:14px">
            <button :class="{ on: tab === 'open' }" @click="tab = 'open'">{{ t('kds.tabOpen') }} <span v-if="openOrders.length" class="pill rose" style="margin-left:4px">{{ openOrders.length }}</span></button>
            <button :class="{ on: tab === 'cooked' }" @click="tab = 'cooked'">{{ t('kds.tabCooked') }}</button>
        </div>

        <template v-if="tab === 'open'">
            <div v-for="o in openOrders" :key="o.id" class="card ticket" style="margin-bottom:14px">
                <div class="ticket-head">
                    <b>{{ SOURCE_ICON[o.order_source] || '🍽️' }} {{ o.table_name || t('restaurant.takeawayLabel') }}</b>
                    <span class="pill rose">{{ o.opened_at }}</span>
                </div>
                <div v-for="it in o.items" :key="it.id" class="ticket-row">
                    <span><b>{{ it.qty }}×</b> {{ it.product_name }}</span>
                    <button class="btn sm" style="width:auto;padding:6px 16px" @click="cook(it)">{{ t('kds.cookButton') }}</button>
                </div>
                <button v-if="o.items.length > 1" class="btn sm ghost" style="margin-top:10px" @click="cookAll(o)">{{ t('kds.cookAllButton') }}</button>
            </div>
            <div v-if="!openOrders.length" class="empty"><div class="big">✅</div>{{ t('kds.allCooked') }}</div>
        </template>

        <template v-else>
            <div v-for="o in cookedOrders" :key="o.id" class="card ticket done" style="margin-bottom:14px">
                <div class="ticket-head"><b>{{ SOURCE_ICON[o.order_source] || '🍽️' }} {{ o.table_name || t('restaurant.takeawayLabel') }}</b></div>
                <div v-for="it in o.items" :key="it.id" class="ticket-row" style="font-size:13.5px">
                    <span><b>{{ it.qty }}×</b> {{ it.product_name }}</span>
                    <span class="pill mint">✓ {{ it.cooked_at }}</span>
                </div>
            </div>
            <div v-if="!cookedOrders.length" class="empty"><div class="big">🍳</div>{{ t('kds.noneCookedYet') }}</div>
        </template>
    </AppLayout>
</template>
