<script setup>
import { Head, router, useForm, usePage } from '@inertiajs/vue3';
import { ref, computed, onMounted, onBeforeUnmount } from 'vue';
import AppLayout from '@/Layouts/AppLayout.vue';
import Sheet from '@/Components/Sheet.vue';
import { useI18n } from '@/composables/useI18n';
import { useToast } from '@/composables/useToast';
import { usePollingReload } from '@/composables/usePollingReload';

const props = defineProps({ tables: Array, takeawayOrders: { type: Array, default: () => [] } });
const { t } = useI18n();
const { toast } = useToast();
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

// settings (kitchen WhatsApp number, pay-first/later, print order) used to
// sit permanently expanded on this screen, pushing the actual tables far
// down every single day just to configure something once — tucked into a
// single sheet now, reached with one small tap instead of always-on clutter
const settingsSheet = ref(false);

// tapping a FREE table used to immediately create an order bound to it —
// the exact "table picked before food" flow Khaled explicitly asked to
// remove (not just supplement with a new button). A free table card is now
// purely a status display; the only way into a new order is "New Order"/
// "Takeaway" above, food first, table picked afterward from the order
// screen (see TableOrderController::assignTable). This nudge is here so a
// tap on a free table doesn't feel like it silently did nothing.
function freeTableTapped() {
    toast('🍽️ ' + t('restaurant.useNewOrderHint'));
}
function goToOrder(tbl) {
    if (tbl.open_order_id) router.visit(route('app.restaurant.orders.show', tbl.open_order_id));
}
// takeaway/parcel customers have no table to sit at — this starts an order
// with no table attached at all, instead of forcing one to be picked first
function startTakeaway() {
    router.post(route('app.restaurant.takeaway.open'));
}
// food-first flow, per Khaled's explicit request: lands straight on the menu
// with no table AND no channel decided yet — both are picked afterward, from
// the order screen itself, once the cart is actually built
function startNewOrder() {
    router.post(route('app.restaurant.order.open'));
}
function removeTable(tbl) {
    if (!confirm(`"${tbl.name}" ${t('restaurant.removeTableConfirm')}`)) return;
    router.delete(route('app.restaurant.tables.destroy', tbl.id));
}

// tables can free up on another device while this screen is open — but not
// while a sheet here is actually mid-submit, same guard the other polled
// screens (Order/Stock) already use, so a background refresh never queues
// in front of something the owner just tapped
const { pollReload } = usePollingReload();
let pollTimer = null;
onMounted(() => {
    pollTimer = setInterval(() => {
        if (addSheet.value || settingsSheet.value) return;
        pollReload({ only: ['tables'], preserveScroll: true });
    }, 10000);
});
onBeforeUnmount(() => clearInterval(pollTimer));
</script>

<template>
    <Head :title="t('restaurant.tablesTitle')" />
    <AppLayout active="restaurant">
        <div style="display:flex;align-items:start;justify-content:space-between;gap:10px">
            <div>
                <div class="pgttl">{{ t('restaurant.tablesTitle') }}</div>
                <div class="pgsub">{{ t('restaurant.tablesSubtitle') }}</div>
            </div>
            <button v-if="isOwner" class="btn ghost sm" style="width:auto;padding:8px 14px;flex:0 0 auto" @click="settingsSheet = true">⚙️ {{ t('common.settings') }}</button>
        </div>

        <!-- food-first, per Khaled's explicit request: build the cart first,
             pick a table / takeaway / delivery afterward from the order
             screen itself — this is now the primary, most prominent action
             on this page instead of tapping a table being the only way in -->
        <button class="btn" style="margin-bottom:8px;background:var(--gold)" @click="startNewOrder">
            🍽️ {{ t('restaurant.newOrder') }}
        </button>
        <!-- kept as a one-tap shortcut for a customer who's clearly not
             sitting down at all — skips even the order-type step -->
        <button class="btn ghost sm" style="margin-bottom:14px" @click="startTakeaway">
            🥡 {{ t('restaurant.newTakeaway') }}
        </button>

        <div v-if="takeawayOrders.length" style="margin-bottom:14px">
            <div class="sechead"><h2>{{ t('restaurant.activeTakeaway') }}</h2></div>
            <div v-for="o in takeawayOrders" :key="o.id" class="row" @click="router.visit(route('app.restaurant.orders.show', o.id))">
                <div class="ava">{{ o.order_source === 'delivery' ? '🛵' : o.order_source === 'takeaway' ? '🥡' : '🍽️' }}</div>
                <div class="mid">
                    <!-- no order #id shown — an internal row number means nothing to a
                         cashier and is one more thing to misread/mix up; the item count
                         + time already tells two open orders apart at a glance, and
                         tapping goes straight into the right one either way -->
                    <b>
                        <template v-if="o.order_source === 'delivery'">{{ t('restaurant.sourceDelivery') }}</template>
                        <template v-else-if="o.order_source === 'takeaway'">{{ t('restaurant.sourceTakeaway') }}</template>
                        <template v-else>{{ t('restaurant.newOrder') }}</template>
                    </b>
                    <span>{{ o.item_count }} {{ t('restaurant.items') }} • {{ o.opened_at }}</span>
                    <span v-if="o.order_source === 'dine_in'" style="color:var(--gold2)">⚠️ {{ t('restaurant.tableNotAssigned') }}</span>
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

        <button class="btn ghost sm" style="margin-bottom:14px" @click="addSheet = true">{{ t('restaurant.addTable') }}</button>

        <div class="pgrid rest-tables-grid">
            <div
                v-for="tb in tables" :key="tb.id" class="pcard"
                :class="{ occupied: tb.status === 'occupied' }"
                @click="tb.status === 'occupied' ? goToOrder(tb) : freeTableTapped()"
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

        <Sheet v-model="settingsSheet" :title="t('common.settings')">
            <div class="card" style="margin-bottom:16px">
                <div style="font-size:12.5px;color:var(--mut);margin-bottom:8px">{{ t('restaurant.kitchenWaHint') }}</div>
                <div style="display:flex;gap:8px">
                    <input v-model="waForm.kitchen_whatsapp" inputmode="tel" placeholder="01XXXXXXXXX" style="flex:1;margin:0">
                    <button class="btn sm" :disabled="waForm.processing" @click="saveKitchenWa">{{ waForm.processing ? '...' : t('stock.save') }}</button>
                </div>
            </div>

            <div class="field" style="margin-bottom:10px">
                <label>{{ t('restaurant.paymentTiming') }}</label>
                <div class="seg">
                    <button :class="{ on: prefsForm.payment_timing === 'pay_later' }" @click="prefsForm.payment_timing = 'pay_later'">{{ t('restaurant.payLater') }}</button>
                    <button :class="{ on: prefsForm.payment_timing === 'pay_first' }" @click="prefsForm.payment_timing = 'pay_first'">{{ t('restaurant.payFirst') }}</button>
                </div>
            </div>
            <div class="field" style="margin-bottom:14px">
                <label>{{ t('restaurant.printOrderPref') }}</label>
                <div class="seg">
                    <button :class="{ on: prefsForm.kitchen_print_order === 'kitchen_first' }" @click="prefsForm.kitchen_print_order = 'kitchen_first'">{{ t('restaurant.kitchenFirst') }}</button>
                    <button :class="{ on: prefsForm.kitchen_print_order === 'customer_first' }" @click="prefsForm.kitchen_print_order = 'customer_first'">{{ t('restaurant.customerFirst') }}</button>
                </div>
            </div>
            <button class="btn" :disabled="prefsForm.processing" @click="savePrefs">{{ prefsForm.processing ? '...' : t('stock.save') }}</button>
        </Sheet>
    </AppLayout>
</template>
