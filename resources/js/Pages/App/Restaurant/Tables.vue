<script setup>
import { Head, router, useForm, usePage } from '@inertiajs/vue3';
import { ref, computed, onMounted, onBeforeUnmount } from 'vue';
import AppLayout from '@/Layouts/AppLayout.vue';
import Sheet from '@/Components/Sheet.vue';
import { useI18n } from '@/composables/useI18n';

const props = defineProps({ tables: Array, takeawayOrders: { type: Array, default: () => [] } });
const { t } = useI18n();
const money = (n) => '৳' + Math.round(n).toLocaleString('en-IN');

const page = usePage();
const isOwner = computed(() => page.props.auth?.user?.role === 'owner');
const waForm = useForm({ kitchen_whatsapp: page.props.shop?.kitchen_whatsapp || '' });
function saveKitchenWa() {
    waForm.patch(route('app.settings.kitchenWhatsapp'), { preserveScroll: true });
}

const prefsForm = useForm({
    payment_timing: page.props.shop?.payment_timing || 'pay_later',
    kitchen_print_order: page.props.shop?.kitchen_print_order || 'kitchen_first',
});
function savePrefs() {
    prefsForm.patch(route('app.settings.restaurantPrefs'), { preserveScroll: true });
}

const occupiedCount = computed(() => props.tables.filter((tbl) => tbl.status === 'occupied').length);
const runningTotal = computed(() => props.tables.reduce((sum, tbl) => sum + Number(tbl.total || 0), 0));

const addSheet = ref(false);
const form = useForm({ name: '' });
function addTable() {
    form.post(route('app.restaurant.tables.store'), { onSuccess: () => { addSheet.value = false; form.reset(); } });
}

function openTable(tbl) {
    router.post(route('app.restaurant.tables.open', tbl.id));
}
function goToOrder(tbl) {
    if (tbl.open_order_id) router.visit(route('app.restaurant.orders.show', tbl.open_order_id));
}
// takeaway/parcel customers have no table to sit at — this starts an order
// with no table attached at all, instead of forcing one to be picked first
function startTakeaway() {
    router.post(route('app.restaurant.takeaway.open'));
}
function removeTable(tbl) {
    if (!confirm(`"${tbl.name}" ${t('restaurant.removeTableConfirm')}`)) return;
    router.delete(route('app.restaurant.tables.destroy', tbl.id));
}

// tables can free up on another device while this screen is open
let pollTimer = null;
onMounted(() => {
    pollTimer = setInterval(() => router.reload({ only: ['tables'], preserveScroll: true }), 10000);
});
onBeforeUnmount(() => clearInterval(pollTimer));
</script>

<template>
    <Head :title="t('restaurant.tablesTitle')" />
    <AppLayout active="restaurant">
        <div class="pgttl">{{ t('restaurant.tablesTitle') }}</div>
        <div class="pgsub">{{ t('restaurant.tablesSubtitle') }}</div>

        <!-- takeaway/parcel has nothing to do with a physical table, so this
             starts one directly instead of being buried behind picking a table -->
        <button class="btn" style="margin-bottom:14px;background:var(--gold)" @click="startTakeaway">
            🥡 {{ t('restaurant.newTakeaway') }}
        </button>

        <div v-if="takeawayOrders.length" style="margin-bottom:14px">
            <div class="sechead"><h2>{{ t('restaurant.activeTakeaway') }}</h2></div>
            <div v-for="o in takeawayOrders" :key="o.id" class="row" @click="router.visit(route('app.restaurant.orders.show', o.id))">
                <div class="ava">🥡</div>
                <div class="mid">
                    <b>{{ o.order_source === 'delivery' ? t('restaurant.sourceDelivery') : t('restaurant.sourceTakeaway') }} #{{ o.id }}</b>
                    <span>{{ o.item_count }} {{ t('restaurant.items') }} • {{ o.opened_at }}</span>
                </div>
                <div class="end"><b>{{ money(o.total) }}</b></div>
            </div>
        </div>

        <div class="grid2" style="margin-bottom:14px">
            <div class="stat rose">
                <div class="k">{{ t('restaurant.occupied') }}</div>
                <div class="v">{{ occupiedCount }} / {{ tables.length }}</div>
            </div>
            <div class="stat mint">
                <div class="k">{{ t('restaurant.runningTotal') }}</div>
                <div class="v">{{ money(runningTotal) }}</div>
            </div>
        </div>

        <button class="btn ghost" style="margin-bottom:14px" @click="addSheet = true">{{ t('restaurant.addTable') }}</button>

        <div v-if="isOwner" class="card" style="margin-bottom:16px">
            <div style="font-size:12.5px;color:var(--mut);margin-bottom:8px">{{ t('restaurant.kitchenWaHint') }}</div>
            <div style="display:flex;gap:8px">
                <input v-model="waForm.kitchen_whatsapp" inputmode="tel" placeholder="01XXXXXXXXX" style="flex:1;margin:0">
                <button class="btn sm" :disabled="waForm.processing" @click="saveKitchenWa">{{ waForm.processing ? '...' : t('stock.save') }}</button>
            </div>
        </div>

        <div v-if="isOwner" class="card" style="margin-bottom:16px">
            <div class="field" style="margin-bottom:10px">
                <label>{{ t('restaurant.paymentTiming') }}</label>
                <div class="seg">
                    <button :class="{ on: prefsForm.payment_timing === 'pay_later' }" @click="prefsForm.payment_timing = 'pay_later'">{{ t('restaurant.payLater') }}</button>
                    <button :class="{ on: prefsForm.payment_timing === 'pay_first' }" @click="prefsForm.payment_timing = 'pay_first'">{{ t('restaurant.payFirst') }}</button>
                </div>
            </div>
            <div class="field" style="margin-bottom:10px">
                <label>{{ t('restaurant.printOrderPref') }}</label>
                <div class="seg">
                    <button :class="{ on: prefsForm.kitchen_print_order === 'kitchen_first' }" @click="prefsForm.kitchen_print_order = 'kitchen_first'">{{ t('restaurant.kitchenFirst') }}</button>
                    <button :class="{ on: prefsForm.kitchen_print_order === 'customer_first' }" @click="prefsForm.kitchen_print_order = 'customer_first'">{{ t('restaurant.customerFirst') }}</button>
                </div>
            </div>
            <button class="btn sm ghost" style="width:100%" :disabled="prefsForm.processing" @click="savePrefs">{{ prefsForm.processing ? '...' : t('stock.save') }}</button>
        </div>

        <div class="pgrid">
            <div
                v-for="tb in tables" :key="tb.id" class="pcard"
                :class="{ occupied: tb.status === 'occupied' }"
                @click="tb.status === 'occupied' ? goToOrder(tb) : openTable(tb)"
            >
                <button class="pcard-main" style="cursor:pointer">
                    <div class="em">{{ tb.status === 'occupied' ? '🍽️' : '🪑' }}</div>
                    <div class="pn">{{ tb.name }}</div>
                    <div v-if="tb.status === 'occupied'" class="pp">{{ money(tb.total) }} <span style="color:var(--dim);font-weight:500">/ {{ tb.item_count }} {{ t('restaurant.items') }}</span></div>
                    <div class="ps">
                        <span v-if="tb.status === 'occupied'" style="color:var(--rose)">{{ t('restaurant.occupied') }}</span>
                        <span v-else style="color:var(--green)">{{ t('restaurant.free') }}</span>
                    </div>
                    <div v-if="tb.status === 'occupied'" style="font-size:10.5px;color:var(--mut);margin-top:4px;line-height:1.5">
                        <div v-if="tb.waiter_name">🧑‍🍳 {{ tb.waiter_name }}</div>
                        <div v-if="tb.order_source && tb.order_source !== 'dine_in'">{{ tb.order_source === 'delivery' ? '🛵' : '🥡' }} {{ tb.order_source === 'delivery' ? t('restaurant.sourceDelivery') : t('restaurant.sourceTakeaway') }}</div>
                        <div v-if="tb.opened_at">🕒 {{ tb.opened_at }}</div>
                        <div v-if="tb.has_unprinted" style="color:var(--gold2);font-weight:700">⏳ {{ t('restaurant.kotPending') }}</div>
                    </div>
                </button>
                <button v-if="tb.status !== 'occupied'" class="btn sm ghost" style="margin-top:6px" @click.stop="removeTable(tb)">{{ t('common.delete') }}</button>
            </div>
        </div>
        <div v-if="!tables.length" class="empty"><div class="big">🍽️</div>{{ t('restaurant.noTables') }}</div>

        <Sheet v-model="addSheet" :title="t('restaurant.addTable')">
            <div class="field">
                <label>{{ t('restaurant.tableName') }}</label>
                <input v-model="form.name" :placeholder="t('restaurant.tableNamePlaceholder')">
                <div v-if="form.errors.name" style="color:var(--rose);font-size:12px;margin-top:6px">{{ form.errors.name }}</div>
            </div>
            <button class="btn" :disabled="form.processing" @click="addTable">{{ form.processing ? '...' : t('stock.save') }}</button>
        </Sheet>
    </AppLayout>
</template>
