<script setup>
import { Head, router, useForm, usePage } from '@inertiajs/vue3';
import { ref, computed, onMounted, onBeforeUnmount } from 'vue';
import AppLayout from '@/Layouts/AppLayout.vue';
import Sheet from '@/Components/Sheet.vue';
import { useI18n } from '@/composables/useI18n';

const props = defineProps({
    order: Object, products: Array, categories: Array,
    otherOccupiedTables: { type: Array, default: () => [] },
    freeTables: { type: Array, default: () => [] },
});
const { t } = useI18n();
const money = (n) => '৳' + Math.round(n).toLocaleString('en-IN');

const page = usePage();
const shop = computed(() => page.props.shop);
// complimentary (free/staff-meal) bills give away real inventory for free —
// owner-only, same reasoning as Pos/Index.vue
const isOwner = computed(() => page.props.auth?.user?.role === 'owner');
// a takeaway/parcel order has no restaurant_table_id at all (see
// RestaurantTableController::openTakeaway), so table_name is null — this is
// the one place that fallback label is needed everywhere the name is shown
const displayName = computed(() => props.order.table_name || t('restaurant.takeawayLabel'));
// preview only, mirrors TableOrderController::bill()'s exact formula — the
// server remains authoritative at billing time, this just shows the
// cashier roughly what to expect while still building the order
const vatPreview = computed(() => {
    if (!shop.value) return 0;
    if (shop.value.vat_mode === 'full') {
        const rate = Number(shop.value.vat_rate) || 0;
        return Math.round(order.total * rate / (100 + rate) * 100) / 100;
    }
    if (shop.value.vat_mode === 'turnover') return Math.round(order.total * (Number(shop.value.turnover_rate) || 0) / 100 * 100) / 100;
    return 0;
});

const q = ref('');
const cat = ref('all');
const filtered = computed(() => props.products.filter((p) =>
    (!q.value
        || p.name.toLowerCase().includes(q.value.toLowerCase())
        || p.name_en?.toLowerCase().includes(q.value.toLowerCase())
        || (p.barcode || '').includes(q.value)
    ) &&
    (cat.value === 'all' || p.category_id === cat.value)
));

// --- pending vs served (separate from kot_printed_at — a kitchen can be
// mid-prep on something already sent but not yet brought to the table) ---
const pendingItems = computed(() => props.order.items.filter((it) => !it.served_at));
const servedItems = computed(() => props.order.items.filter((it) => it.served_at));
function toggleServed(item) {
    router.post(route('app.restaurant.orderItems.toggleServed', item.id), {}, { preserveScroll: true });
}

// --- order source (dine-in/takeaway/3rd-party delivery) + kitchen note ---
const DELIVERY_PLATFORMS = ['Food Panda', 'Pathao Food', 'HungryNaki', 'Chaldal', 'অন্য কিছু'];
const metaForm = useForm({
    order_source: props.order.order_source || 'dine_in',
    delivery_platform: props.order.delivery_platform || '',
    kitchen_note: props.order.kitchen_note || '',
    waiter_name: props.order.waiter_name || '',
});
const metaChanged = computed(() =>
    metaForm.order_source !== (props.order.order_source || 'dine_in') ||
    metaForm.delivery_platform !== (props.order.delivery_platform || '') ||
    metaForm.kitchen_note !== (props.order.kitchen_note || '') ||
    metaForm.waiter_name !== (props.order.waiter_name || '')
);
function saveMeta() {
    metaForm.patch(route('app.restaurant.orders.meta', props.order.id), { preserveScroll: true });
}

function addItem(p) {
    if (p.stock <= 0) return;
    router.post(route('app.restaurant.orders.items.store', props.order.id), { product_id: p.id, qty: 1 }, { preserveScroll: true });
}
function incItem(item) {
    router.post(route('app.restaurant.orders.items.store', props.order.id), { product_id: item.product_id, qty: 1 }, { preserveScroll: true });
}
function decItem(item) {
    router.patch(route('app.restaurant.orderItems.decrement', item.id), {}, { preserveScroll: true });
}
function removeItem(item) {
    router.delete(route('app.restaurant.orderItems.destroy', item.id), { preserveScroll: true });
}

function printKot() {
    router.post(route('app.restaurant.orders.kot', props.order.id), {}, {
        preserveScroll: true,
        onSuccess: () => setTimeout(() => window.print(), 200),
    });
}

// one-click combo: sends whatever hasn't reached the kitchen yet (same
// action as the "Print KOT" button) and, without a second tap, opens the
// bill sheet right after — for a cashier who wants both done together
// instead of pressing two separate buttons in sequence
function billAndPrintKot() {
    if (splitMode.value && !selectedItemIds.value.length) return;
    printKot();
    billSheet.value = true;
}

// same one-click idea, but sends the kitchen ticket over WhatsApp instead of
// printing it — for a kitchen that reads orders off a phone, not a printer
function billAndSendKotWA() {
    if (splitMode.value && !selectedItemIds.value.length) return;
    sendKotWA();
    billSheet.value = true;
}

// UI-only preference (see the migration's comment) — reorders/relabels the
// kitchen-send vs bill-now actions below, never blocks either one
const payFirst = computed(() => shop.value?.payment_timing === 'pay_first');

const kitchenWaNumber = computed(() => shop.value?.kitchen_whatsapp || '');
function sendKotWA() {
    const num = '88' + kitchenWaNumber.value.replace(/\D/g, '').replace(/^88/, '');
    const lines = props.order.items.map((it) => `${it.product_name}  ×${it.qty}`).join('\n');
    const sourceLine = props.order.order_source === 'delivery'
        ? `\n🛵 ${t('restaurant.sourceDelivery')} — ${props.order.delivery_platform || ''}`
        : props.order.order_source === 'takeaway' ? `\n🥡 ${t('restaurant.sourceTakeaway')}` : '';
    const noteLine = props.order.kitchen_note ? `\n📝 ${props.order.kitchen_note}` : '';
    const text = `*${t('restaurant.kotTitle')}*\n${'─'.repeat(16)}\n${displayName.value}${sourceLine}\n${'─'.repeat(16)}\n${lines}${noteLine}`;
    // mark as sent to kitchen too, same as the print button — WhatsApp is
    // just an alternate delivery channel for the same "sent to kitchen" event
    router.post(route('app.restaurant.orders.kot', props.order.id), {}, {
        preserveScroll: true,
        onSuccess: () => window.open('https://wa.me/' + num + '?text=' + encodeURIComponent(text), '_blank'),
    });
}

const cancelOrder = () => {
    if (!confirm(t('restaurant.cancelOrderConfirm'))) return;
    router.post(route('app.restaurant.orders.cancel', props.order.id));
};

// --- split bill — off by default (bills everything, exactly like before);
// toggling it on shows a checkbox per line and only sends those item_ids ---
const splitMode = ref(false);
const selectedItemIds = ref([]);
function toggleSplitMode() {
    splitMode.value = !splitMode.value;
    selectedItemIds.value = [];
}
function toggleItemSelected(itemId) {
    const i = selectedItemIds.value.indexOf(itemId);
    if (i === -1) selectedItemIds.value.push(itemId);
    else selectedItemIds.value.splice(i, 1);
}
const splitSubtotal = computed(() => props.order.items
    .filter((it) => selectedItemIds.value.includes(it.id))
    .reduce((s, it) => s + it.price * it.qty, 0));

// --- billing sheet ---
const billSheet = ref(false);
const payMode = ref('cash');
const discount = ref(0);
const customerPhone = ref('');
const customerName = ref('');
const billBaseTotal = computed(() => (splitMode.value ? splitSubtotal.value : props.order.total));
// complimentary forces the server to charge nothing (discount = subtotal,
// see TableOrderController::bill()) — the displayed total must reflect that
// the instant "🎁 Complimentary" is picked, not just after the bill is sent
const effectiveDiscount = computed(() => (payMode.value === 'complimentary' ? billBaseTotal.value : (discount.value || 0)));
// mirrors TableOrderController::bill()'s own math — additive on top of the
// discounted subtotal, unlike the VAT preview above which is backed out of
// an already-inclusive price and never added to what's actually charged
const serviceCharge = computed(() => {
    const rate = shop.value?.service_charge_rate;
    if (rate === null || rate === undefined) return 0;
    return Math.round(Math.max(0, billBaseTotal.value - effectiveDiscount.value) * Number(rate) / 100 * 100) / 100;
});
const billTotal = computed(() => Math.max(0, billBaseTotal.value - effectiveDiscount.value) + serviceCharge.value);
const billForm = useForm({});
function openBillSheet() {
    if (splitMode.value && !selectedItemIds.value.length) return;
    billSheet.value = true;
}
function submitBill() {
    billForm.transform(() => ({
        discount: discount.value || 0,
        complimentary: payMode.value === 'complimentary',
        payments: (payMode.value === 'credit' || payMode.value === 'complimentary') ? [] : [{ method: payMode.value, amount: billTotal.value }],
        customer_phone: customerPhone.value,
        customer_name: customerName.value,
        item_ids: splitMode.value ? selectedItemIds.value : undefined,
    })).post(route('app.restaurant.orders.bill', props.order.id), {
        onSuccess: () => { splitMode.value = false; selectedItemIds.value = []; },
    });
}

// --- table transfer / merge ---
const tableActionSheet = ref(false);
const tableActionMode = ref('transfer'); // 'transfer' this order away, or 'merge' another table's order into this one
const tableActionTargetId = ref('');
function submitTableAction() {
    if (!tableActionTargetId.value) return;
    if (tableActionMode.value === 'transfer') {
        router.post(route('app.restaurant.tables.transfer', props.order.table_id), { to_table_id: tableActionTargetId.value }, {
            onSuccess: () => (tableActionSheet.value = false),
        });
    } else {
        router.post(route('app.restaurant.tables.merge', props.order.table_id), { from_table_id: tableActionTargetId.value }, {
            onSuccess: () => (tableActionSheet.value = false),
        });
    }
}

// transfer/merge/cancel are rare, one-off actions — tucked behind a single
// header button instead of sitting in the main scroll flow, so they're
// still reachable in one tap but never push the everyday fields further down
const moreSheet = ref(false);

// another device could add/remove items on the same table order
let pollTimer = null;
onMounted(() => {
    pollTimer = setInterval(() => {
        if (billSheet.value) return;
        router.reload({ only: ['order'], preserveScroll: true, preserveState: true });
    }, 8000);
});
onBeforeUnmount(() => clearInterval(pollTimer));
</script>

<template>
    <Head :title="displayName" />
    <AppLayout active="restaurant">
        <div style="display:flex;align-items:start;justify-content:space-between;gap:10px">
            <div>
                <div class="pgttl">{{ displayName }}</div>
                <div class="pgsub">{{ t('restaurant.orderTitle') }} • {{ money(order.total) }}</div>
            </div>
            <button class="btn ghost sm" style="width:auto;padding:8px 14px;flex:0 0 auto" @click="moreSheet = true">⋯ {{ t('common.more') }}</button>
        </div>

        <div class="lg:flex lg:gap-6 lg:items-start">
            <!-- order/cart panel — natural first on mobile (unchanged position), becomes the sticky right column on desktop.
                 Cart items come first (what a cashier actually needs to see/manage the instant they open an order); order-
                 type/waiter/kitchen-note are set once and rarely touched again, so they sit below the bill actions, not
                 above the items — restored here after briefly moving them to the top to mirror a reference screenshot,
                 which undid an earlier, already-confirmed-good fix (see git history: cart-first was explicitly validated
                 by the user before this file mirrored a different reference's top-first layout — that reference's layout
                 wasn't actually requested to replace this one). -->
            <div class="lg:order-3 lg:w-80 lg:shrink-0 lg:sticky lg:top-6 order-panel">
                <div class="card" style="margin-bottom:14px">
                    <div v-if="order.items.length">
                        <div v-if="order.items.length > 1" style="display:flex;justify-content:space-between;align-items:center;margin-bottom:8px">
                            <span style="font-size:11px;font-weight:800;color:var(--dim);text-transform:uppercase">{{ t('restaurant.splitBillToggle') }}</span>
                            <button class="btn sm ghost" style="width:auto;padding:4px 12px" @click="toggleSplitMode">{{ splitMode ? t('common.cancel') : t('restaurant.splitBillStart') }}</button>
                        </div>
                        <div v-if="pendingItems.length" style="font-size:11px;font-weight:800;color:var(--dim);text-transform:uppercase;margin-bottom:4px">{{ t('restaurant.pendingItems') }}</div>
                        <div v-for="it in pendingItems" :key="it.id" style="margin-bottom:6px">
                            <div class="cart-line">
                                <input v-if="splitMode" type="checkbox" style="width:auto;margin-right:2px" :checked="selectedItemIds.includes(it.id)" @change="toggleItemSelected(it.id)">
                                <div class="nm">
                                    <b>{{ it.product_name }}</b>
                                    <span>{{ money(it.price) }} {{ t('restaurant.each') }} = {{ money(it.qty * it.price) }}</span>
                                    <span v-if="it.kot_printed_at" style="color:var(--green)">✓ {{ t('restaurant.sentToKitchen') }}</span>
                                </div>
                                <template v-if="!splitMode">
                                    <button class="qbtn" @click="decItem(it)">−</button>
                                    <span class="qn">{{ it.qty }}</span>
                                    <button class="qbtn" @click="incItem(it)">＋</button>
                                </template>
                            </div>
                            <button v-if="!splitMode" class="btn sm ghost" style="width:100%" @click="toggleServed(it)">✅ {{ t('restaurant.markServed') }}</button>
                        </div>
                        <div v-if="splitMode && selectedItemIds.length" class="card" style="margin:8px 0;padding:8px 12px;background:var(--surface2, var(--card))">
                            <div style="display:flex;justify-content:space-between;font-size:13px;font-weight:700"><span>{{ t('restaurant.splitBillSelectedTotal') }}</span><span>{{ money(splitSubtotal) }}</span></div>
                        </div>

                        <div v-if="servedItems.length" style="font-size:11px;font-weight:800;color:var(--dim);text-transform:uppercase;margin:10px 0 4px">{{ t('restaurant.servedItems') }}</div>
                        <div v-for="it in servedItems" :key="it.id" class="cart-line" style="opacity:.6">
                            <div class="nm">
                                <b>{{ it.product_name }}</b>
                                <span>{{ money(it.price) }} {{ t('restaurant.each') }} = {{ money(it.qty * it.price) }}</span>
                                <span style="color:var(--green)">✓ {{ t('restaurant.served') }}</span>
                            </div>
                            <button class="btn sm ghost" @click="toggleServed(it)">↩ {{ t('restaurant.undoServed') }}</button>
                        </div>
                    </div>
                    <div v-else class="empty" style="padding:20px 0"><div class="big">🍽️</div>{{ t('restaurant.noItemsYet') }}</div>
                </div>

                <!-- sticky footer — subtotal + total + bill/kitchen actions stay on
                     screen while the cart above scrolls, so the total and the
                     buttons a cashier needs most are never scrolled out of view,
                     the same way on mobile, tablet and desktop -->
                <div class="sticky-footer">
                    <div v-if="order.items.length" class="card" style="margin-bottom:8px;padding:10px 14px">
                        <div style="display:flex;justify-content:space-between;font-size:13px;color:var(--mut);padding:2px 0"><span>{{ t('pos.subtotal') }}</span><span>{{ money(order.total) }}</span></div>
                        <div v-if="vatPreview > 0" style="display:flex;justify-content:space-between;font-size:13px;color:var(--mut);padding:2px 0"><span>{{ t('restaurant.vatEstimate') }}</span><span>{{ money(vatPreview) }}</span></div>
                    </div>

                    <!-- order flips to bill-first when the shop's payment_timing preference is pay_first — the actions themselves are identical either way, only which one a cashier sees emphasized first changes -->
                    <template v-if="payFirst">
                        <div class="btnrow" style="margin-bottom:6px">
                            <button class="btn sm" style="flex:1" :disabled="!order.items.length || (splitMode && !selectedItemIds.length)" @click="openBillSheet">{{ splitMode ? t('restaurant.billSelected') : t('restaurant.billNow') }}</button>
                        </div>
                        <div class="btnrow" style="margin-bottom:6px">
                            <button class="btn ghost sm" style="flex:1" @click="printKot">{{ t('restaurant.printKot') }}</button>
                            <button v-if="kitchenWaNumber" class="btn wa sm" style="flex:1" @click="sendKotWA">📤 {{ t('restaurant.kotWa') }}</button>
                        </div>
                    </template>
                    <template v-else>
                        <div class="btnrow" style="margin-bottom:6px">
                            <button class="btn ghost sm" style="flex:1" @click="printKot">{{ t('restaurant.printKot') }}</button>
                            <button v-if="kitchenWaNumber" class="btn wa sm" style="flex:1" @click="sendKotWA">📤 {{ t('restaurant.kotWa') }}</button>
                        </div>
                        <div class="btnrow" style="margin-bottom:6px">
                            <button class="btn sm" style="flex:1" :disabled="!order.items.length || (splitMode && !selectedItemIds.length)" @click="openBillSheet">{{ splitMode ? t('restaurant.billSelected') : t('restaurant.billNow') }}</button>
                        </div>
                    </template>
                    <button class="btn" style="margin-bottom:6px;background:var(--green)" :disabled="!order.items.length || (splitMode && !selectedItemIds.length)" @click="billAndPrintKot">
                        🧾 {{ t('restaurant.billAndKot') }}
                    </button>
                    <button v-if="kitchenWaNumber" class="btn wa" :disabled="!order.items.length || (splitMode && !selectedItemIds.length)" @click="billAndSendKotWA">
                        📤 {{ t('restaurant.billAndKotWa') }}
                    </button>
                </div>

                <!-- secondary details — order type, kitchen note, waiter — set
                     once per order, not needed at a glance every time like the
                     cart/bill actions above are, so they sit below those, not above -->
                <div class="card" style="margin-bottom:14px">
                    <div class="field" style="margin-bottom:8px">
                        <label>{{ t('restaurant.orderSource') }}</label>
                        <div class="seg">
                            <button :class="{ on: metaForm.order_source === 'dine_in' }" @click="metaForm.order_source = 'dine_in'">{{ t('restaurant.sourceDineIn') }}</button>
                            <button :class="{ on: metaForm.order_source === 'takeaway' }" @click="metaForm.order_source = 'takeaway'">{{ t('restaurant.sourceTakeaway') }}</button>
                            <button :class="{ on: metaForm.order_source === 'delivery' }" @click="metaForm.order_source = 'delivery'">{{ t('restaurant.sourceDelivery') }}</button>
                        </div>
                    </div>
                    <div v-if="metaForm.order_source === 'delivery'" class="field" style="margin-bottom:8px">
                        <label>{{ t('restaurant.deliveryPlatform') }}</label>
                        <select v-model="metaForm.delivery_platform">
                            <option value="">{{ t('damage.selectPlaceholder') }}</option>
                            <option v-for="p in DELIVERY_PLATFORMS" :key="p" :value="p">{{ p }}</option>
                        </select>
                        <input v-if="metaForm.delivery_platform === 'অন্য কিছু'" v-model="metaForm.delivery_platform" :placeholder="t('restaurant.deliveryPlatformCustom')" style="margin-top:6px">
                    </div>
                    <div class="field" style="margin-bottom:8px">
                        <label>{{ t('restaurant.kitchenNote') }} <span style="color:var(--dim);font-weight:400">{{ t('restaurant.kitchenNoteHint') }}</span></label>
                        <textarea v-model="metaForm.kitchen_note" rows="2" :placeholder="t('restaurant.kitchenNotePlaceholder')"></textarea>
                    </div>
                    <div class="field" style="margin-bottom:8px">
                        <label>{{ t('restaurant.waiterName') }} <span style="color:var(--dim);font-weight:400">{{ t('stock.optional') }}</span></label>
                        <input v-model="metaForm.waiter_name" :placeholder="t('restaurant.waiterNamePlaceholder')">
                    </div>
                    <button v-if="metaChanged" class="btn sm ghost" style="width:100%" :disabled="metaForm.processing" @click="saveMeta">{{ metaForm.processing ? '...' : t('stock.save') }}</button>
                </div>
            </div>

            <!-- category rail — desktop only, the mobile tabbar below covers the same job on phone -->
            <aside class="hidden lg:block lg:order-1 lg:w-52 lg:shrink-0 lg:sticky lg:top-6">
                <button class="rest-rail-btn" :class="{ on: cat === 'all' }" @click="cat = 'all'">{{ t('pos.allProducts') }}</button>
                <button v-for="c in categories" :key="c.id" class="rest-rail-btn" :class="{ on: cat === c.id }" @click="cat = c.id">{{ c.emoji }} {{ c.name }}</button>
            </aside>

            <!-- menu -->
            <div class="lg:order-2 lg:flex-1 lg:min-w-0">
                <input v-model="q" :placeholder="t('pos.searchPlaceholder')" style="margin-bottom:12px">
                <div class="tabbar lg:hidden">
                    <button :class="{ on: cat === 'all' }" @click="cat = 'all'">{{ t('pos.allProducts') }}</button>
                    <button v-for="c in categories" :key="c.id" :class="{ on: cat === c.id }" @click="cat = c.id">{{ c.emoji }} {{ c.name }}</button>
                </div>

                <div v-if="filtered.length" class="pgrid rest-grid">
                    <div v-for="p in filtered" :key="p.id" class="pcard">
                        <button class="pcard-main" @click="addItem(p)">
                            <img v-if="p.photo_url" :src="p.photo_url" class="pimg" :alt="p.name">
                            <div v-else class="em">{{ p.emoji }}</div>
                            <div class="pn">{{ p.name }}</div>
                            <div class="pp">{{ money(p.price) }}</div>
                            <div class="ps">
                                <span v-if="p.stock > 0">{{ t('pos.inStock', { n: p.stock }) }}</span>
                                <span v-else style="color:var(--rose)">{{ t('pos.outOfStock') }}</span>
                            </div>
                        </button>
                    </div>
                </div>
                <div v-else class="empty"><div class="big">🔍</div>{{ t('pos.notFound') }}</div>
            </div>
        </div>

        <!-- KOT print view — kitchen ticket, no prices -->
        <Teleport to="body">
            <div id="printable-kot">
                <h3>{{ t('restaurant.kotTitle') }}</h3>
                <div class="rc-sub">
                    {{ displayName }} • {{ new Date().toLocaleString() }}
                    <template v-if="order.order_source === 'delivery'"><br>🛵 {{ t('restaurant.sourceDelivery') }} — {{ order.delivery_platform }}</template>
                    <template v-else-if="order.order_source === 'takeaway'"><br>🥡 {{ t('restaurant.sourceTakeaway') }}</template>
                </div>
                <div v-for="it in order.items" :key="it.id" class="rc-l">
                    <span>{{ it.product_name }}</span><b>×{{ it.qty }}</b>
                </div>
                <div v-if="order.kitchen_note" class="rc-note">📝 {{ order.kitchen_note }}</div>
            </div>
        </Teleport>

        <!-- billing sheet -->
        <Sheet v-model="billSheet" :title="t('restaurant.billSheetTitle')">
            <div class="field">
                <label>{{ t('pos.mobileLabel') }} <span style="color:var(--dim);font-weight:400">{{ t('pos.mobileHint') }}</span></label>
                <input v-model="customerPhone" inputmode="tel" placeholder="01XXXXXXXXX">
            </div>
            <div class="field">
                <label>{{ t('pos.customerName') }}</label>
                <input v-model="customerName" :placeholder="t('pos.customerName')">
            </div>
            <div class="field">
                <label>{{ t('pos.payment') }}</label>
                <div v-if="payMode !== 'complimentary'" class="seg">
                    <button :class="{ on: payMode === 'cash' }" @click="payMode = 'cash'">{{ t('pay.cash') }}</button>
                    <button :class="{ on: payMode === 'bkash' }" @click="payMode = 'bkash'">{{ t('pay.bkash') }}</button>
                    <button :class="{ on: payMode === 'nagad' }" @click="payMode = 'nagad'">{{ t('pay.nagad') }}</button>
                    <button :class="{ on: payMode === 'credit' }" @click="payMode = 'credit'">{{ t('pay.credit') }}</button>
                </div>
                <button v-if="isOwner && payMode !== 'complimentary'" type="button" class="btn sm ghost" style="margin-top:8px;color:var(--gold2);border-color:var(--gold2)" @click="payMode = 'complimentary'">🎁 {{ t('pay.complimentary') }}</button>
                <div v-if="payMode === 'complimentary'" class="card" style="margin-top:8px;background:var(--goldSoft);border-color:var(--gold2)">
                    <div style="font-size:13px;font-weight:700;color:var(--gold2)">🎁 {{ t('pay.complimentaryActiveHint') }}</div>
                    <button type="button" class="btn sm ghost" style="margin-top:8px" @click="payMode = 'cash'">{{ t('common.cancel') }}</button>
                </div>
            </div>
            <div class="field">
                <label>{{ t('pos.overallDiscount') }}</label>
                <input v-model.number="discount" type="number" placeholder="0" min="0">
            </div>
            <div class="card" style="margin-bottom:14px">
                <div v-if="effectiveDiscount > 0" style="display:flex;justify-content:space-between;font-size:13px;color:var(--mut);padding-bottom:6px"><span>{{ t('pos.overallDiscount') }}</span><span>− {{ money(effectiveDiscount) }}</span></div>
                <div v-if="serviceCharge > 0" style="display:flex;justify-content:space-between;font-size:13px;color:var(--mut);padding-bottom:6px"><span>{{ t('pos.serviceCharge') }}</span><span>+ {{ money(serviceCharge) }}</span></div>
                <div style="display:flex;justify-content:space-between;font-size:19px;font-weight:800"><span>{{ t('pos.grandTotal') }}</span><b style="color:var(--gold)">{{ money(billTotal) }}</b></div>
            </div>
            <button class="btn" :disabled="billForm.processing" @click="submitBill">
                {{ billForm.processing ? t('pos.processing') : t('restaurant.billNow') }}
            </button>
        </Sheet>

        <!-- table move/merge + cancel — rare actions, reachable in one tap from
             the header's ⋯ button instead of sitting in the everyday scroll flow -->
        <Sheet v-model="moreSheet" :title="t('common.more')">
            <button v-if="freeTables.length" class="btn ghost" style="margin-bottom:10px" @click="moreSheet = false; tableActionMode = 'transfer'; tableActionTargetId = ''; tableActionSheet = true">🔀 {{ t('restaurant.transferTable') }}</button>
            <button v-if="otherOccupiedTables.length" class="btn ghost" style="margin-bottom:10px" @click="moreSheet = false; tableActionMode = 'merge'; tableActionTargetId = ''; tableActionSheet = true">🔗 {{ t('restaurant.mergeTable') }}</button>
            <button class="btn ghost" style="color:var(--rose);border-color:var(--rose)" @click="moreSheet = false; cancelOrder()">{{ t('restaurant.cancelOrder') }}</button>
        </Sheet>

        <!-- table transfer / merge -->
        <Sheet v-model="tableActionSheet" :title="tableActionMode === 'transfer' ? t('restaurant.transferTable') : t('restaurant.mergeTable')">
            <div class="field">
                <label>{{ tableActionMode === 'transfer' ? t('restaurant.transferToLabel') : t('restaurant.mergeFromLabel') }}</label>
                <select v-model="tableActionTargetId">
                    <option value="">{{ t('damage.selectPlaceholder') }}</option>
                    <option v-for="t2 in (tableActionMode === 'transfer' ? freeTables : otherOccupiedTables)" :key="t2.id" :value="t2.id">{{ t2.name }}</option>
                </select>
            </div>
            <div style="font-size:12px;color:var(--dim);margin-bottom:14px">
                {{ tableActionMode === 'transfer' ? t('restaurant.transferHint') : t('restaurant.mergeHint') }}
            </div>
            <button class="btn" :disabled="!tableActionTargetId" @click="submitTableAction">{{ t('stock.save') }}</button>
            <button class="btn ghost" style="margin-top:10px" @click="tableActionSheet = false">{{ t('common.cancel') }}</button>
        </Sheet>
    </AppLayout>
</template>

<style>
@media print {
    @page { size: 58mm auto; margin: 0; }
    #app { display: none !important; }
    #printable-kot {
        width: 58mm; padding: 2mm 3mm; font-family: monospace; font-size: 12px;
    }
    #printable-kot h3 { font-size: 14px; margin: 0 0 4px; text-align: center; }
    #printable-kot .rc-sub { font-size: 10px; text-align: center; margin-bottom: 6px; }
    #printable-kot .rc-l { display: flex; justify-content: space-between; padding: 2px 0; border-bottom: 1px dashed #999; }
    #printable-kot .rc-note { margin-top: 6px; padding-top: 4px; border-top: 1px dashed #999; font-weight: bold; white-space: pre-wrap; }
}
#printable-kot { display: none; }
@media print { #printable-kot { display: block; } }
</style>
