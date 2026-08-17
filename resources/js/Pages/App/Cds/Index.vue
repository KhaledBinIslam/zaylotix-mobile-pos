<script setup>
import { Head, router } from '@inertiajs/vue3';
import { ref, computed, onMounted, onBeforeUnmount } from 'vue';
import AppLayout from '@/Layouts/AppLayout.vue';
import { useI18n } from '@/composables/useI18n';
import { usePollingReload } from '@/composables/usePollingReload';

const props = defineProps({ products: Array, categories: Array, todaysOrders: Array });
const { t } = useI18n();
const money = (n) => '৳' + Math.round(n).toLocaleString('en-IN');

const cat = ref('all');
const filtered = computed(() => props.products.filter((p) => cat.value === 'all' || p.category_id === cat.value));

const STATUS_LABEL = { pending: 'kds.tabOpen', cooked: 'kds.tabCooked', served: 'restaurant.served' };
const STATUS_COLOR = { pending: 'var(--rose)', cooked: 'var(--gold2)', served: 'var(--green)' };

// read-only screen meant for a second monitor/tablet facing the customer —
// only the order-status side needs to stay live, the menu itself barely
// ever changes mid-service
const { pollReload } = usePollingReload();
let pollTimer = null;
onMounted(() => {
    pollTimer = setInterval(() => {
        pollReload({ only: ['todaysOrders'], preserveScroll: true });
    }, 8000);
});
onBeforeUnmount(() => clearInterval(pollTimer));
</script>

<template>
    <Head :title="t('cds.title')" />
    <AppLayout active="cds">
        <div class="pgttl">{{ t('cds.title') }}</div>
        <div class="pgsub">{{ t('cds.subtitle') }}</div>

        <div class="lg:flex lg:gap-6 lg:items-start">
            <div class="lg:order-1 lg:flex-1 lg:min-w-0">
                <div class="sechead"><h2>{{ t('cds.menuHeading') }}</h2></div>
                <div class="tabbar" style="margin-bottom:12px">
                    <button :class="{ on: cat === 'all' }" @click="cat = 'all'">{{ t('pos.allProducts') }}</button>
                    <button v-for="c in categories" :key="c.id" :class="{ on: cat === c.id }" @click="cat = c.id">{{ c.emoji }} {{ c.name }}</button>
                </div>
                <div class="pgrid rest-grid">
                    <div v-for="p in filtered" :key="p.id" class="pcard">
                        <div class="pcard-main" style="cursor:default">
                            <img v-if="p.photo_url" :src="p.photo_url" class="pimg" :alt="p.name">
                            <div v-else class="em">{{ p.emoji }}</div>
                            <div class="pn">{{ p.name }}</div>
                            <div class="pp">{{ money(p.price) }}</div>
                        </div>
                    </div>
                </div>
                <div v-if="!filtered.length" class="empty"><div class="big">🍽️</div>{{ t('pos.notFound') }}</div>
            </div>

            <aside class="lg:order-2 lg:w-80 lg:shrink-0">
                <div class="sechead"><h2>{{ t('cds.ordersHeading') }}</h2></div>
                <div v-for="o in todaysOrders" :key="o.id" class="card" style="margin-bottom:12px">
                    <b style="font-size:14.5px">{{ o.table_name || t('restaurant.takeawayLabel') }}</b>
                    <div v-for="it in o.items" :key="it.id" style="display:flex;justify-content:space-between;padding:5px 0;font-size:13px;border-top:1px solid var(--line)">
                        <span>{{ it.qty }}× {{ it.product_name }}</span>
                        <span :style="{ color: STATUS_COLOR[it.status], fontWeight: 700 }">{{ t(STATUS_LABEL[it.status]) }}</span>
                    </div>
                </div>
                <div v-if="!todaysOrders.length" class="empty"><div class="big">🍽️</div>{{ t('cds.noOrdersToday') }}</div>
            </aside>
        </div>
    </AppLayout>
</template>
